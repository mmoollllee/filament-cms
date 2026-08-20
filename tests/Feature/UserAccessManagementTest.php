<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Filament\Resources\Users\Pages\ListUsers;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

/**
 * Two different things a panel calls "removing a user", kept apart on purpose:
 * ending a MEMBERSHIP (tenant-scoped, what a site admin means) and deleting the
 * ACCOUNT (global, and therefore superadmin-only) — see UserPolicy.
 */
it('lets a tenant admin end a membership without touching the account', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');

    $member = User::factory()->create();
    $other = Tenant::factory()->create();
    $tenant->addUser($member, TenantUserRole::Editor);
    $other->addUser($member, TenantUserRole::Editor);

    Livewire::test(ListUsers::class)
        // Rows are arrays keyed by type, not Eloquent records — members and
        // pending invitations share one table.
        ->callAction(TestAction::make('detachFromTenant')->table('member-'.$member->getKey()));

    expect($tenant->fresh()->hasUser($member))->toBeFalse()
        ->and(User::query()->whereKey($member->getKey())->exists())->toBeTrue()
        ->and($other->fresh()->hasUser($member))->toBeTrue();
});

it('keeps account deletion away from tenant admins', function () {
    actingAsMarketingPanelUser('admin-a@example.test');

    $member = User::factory()->create();
    Filament::getTenant()->addUser($member, TenantUserRole::Editor);

    expect(auth()->user()->can('delete', $member))->toBeFalse()
        ->and(auth()->user()->can('detach', $member))->toBeTrue();
});

it('lets a superadmin delete the account itself', function () {
    actingAsMarketingPanelUser('admin-a@example.test');

    $superadmin = User::factory()->create(['is_superadmin' => true]);
    $this->actingAs($superadmin);

    $member = User::factory()->create();
    Filament::getTenant()->addUser($member, TenantUserRole::Editor);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('delete')->table('member-'.$member->getKey()));

    expect(User::query()->whereKey($member->getKey())->exists())->toBeFalse();
});

it('never offers either removal for the acting user', function () {
    actingAsMarketingPanelUser('admin-a@example.test');

    $self = auth()->user();

    expect($self->can('detach', $self))->toBeFalse()
        ->and($self->can('delete', $self))->toBeFalse();
});

it('offers direct assignment to superadmins only', function () {
    actingAsMarketingPanelUser('admin-a@example.test');

    Livewire::test(ListUsers::class)->assertActionHidden('assignExisting');

    $this->actingAs(User::factory()->create(['is_superadmin' => true]));

    Livewire::test(ListUsers::class)->assertActionVisible('assignExisting');
});

it('assigns an existing account to the tenant without a mail', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');

    $this->actingAs(User::factory()->create(['is_superadmin' => true]));
    $newcomer = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->callAction('assignExisting', [
            'user_ids' => [$newcomer->getKey()],
            'role' => TenantUserRole::Admin->value,
        ]);

    expect($newcomer->fresh()->tenantRole($tenant))->toBe(TenantUserRole::Admin);
});

it('leaves current members out of the assignment picker', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');

    $this->actingAs(User::factory()->create(['is_superadmin' => true]));
    $member = User::factory()->create();
    $tenant->addUser($member, TenantUserRole::Editor);
    $outsider = User::factory()->create();

    $options = (new ReflectionMethod(ListUsers::class, 'assignableUserOptions'))
        ->invoke(app(ListUsers::class));

    expect($options)->not->toHaveKey($member->getKey())
        ->and($options)->toHaveKey($outsider->getKey());
});
