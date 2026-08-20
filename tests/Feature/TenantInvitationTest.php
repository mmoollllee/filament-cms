<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Filament\Pages\Auth\Register;
use Mmoollllee\Cms\Filament\Resources\Users\Pages\ListUsers;
use Mmoollllee\Cms\Mail\TenantInvitationMail;
use Mmoollllee\Cms\Models\TenantInvitation;
use Mmoollllee\Cms\Support\Tenancy\TenantInvitations;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

/**
 * Inviting somebody to a tenant: the service, the signed accept endpoint and
 * the invitation-only registration page that catches invitees without an
 * account.
 */
beforeEach(function () {
    Mail::fake();
});

function inviteToTenant(Tenant $tenant, string $email = 'neu@example.test', ?User $by = null): TenantInvitation
{
    return app(TenantInvitations::class)->invite($tenant, $email, TenantUserRole::Editor, $by);
}

it('sends a signed invitation with a token and an expiry', function () {
    $tenant = Tenant::factory()->create();

    $invitation = inviteToTenant($tenant);

    expect($invitation->token)->toHaveLength(64)
        ->and($invitation->expires_at)->not->toBeNull()
        ->and($invitation->role)->toBe(TenantUserRole::Editor)
        ->and($invitation->isPending())->toBeTrue();

    // Queued, not sent: the mailable implements ShouldQueue + afterCommit so a
    // worker can never outrun the row it links to.
    Mail::assertQueued(TenantInvitationMail::class, fn (TenantInvitationMail $mail): bool => $mail->hasTo('neu@example.test'));
});

it('renders the invitation mail', function () {
    // Mail::assertQueued proves a mailable was DISPATCHED, never that it can be
    // built — the view is compiled inside the worker. A Blade error there fails
    // the job long after every test went green, so the render belongs in one.
    $tenant = Tenant::factory()->create(['name' => 'Kundenseite', 'primary_domain' => 'kunde.test']);
    $inviter = User::factory()->create(['name' => 'Anna Admin']);

    $invitation = app(TenantInvitations::class)
        ->invite($tenant, 'neu@example.test', TenantUserRole::Admin, $inviter);

    $html = (new TenantInvitationMail($invitation))->render();

    expect($html)->toContain('Anna Admin')
        ->toContain('als Admin')
        // Escaped in the markup, so match the escaped form of the whole link.
        ->toContain(e($invitation->acceptUrl()));
});

it('renders the invitation mail for a direct invite with no inviter', function () {
    // The invite action always passes the acting user, but the service allows
    // none (a console invite, a seeder) — the mail has to survive that.
    $tenant = Tenant::factory()->create(['primary_domain' => 'kunde.test']);

    expect((new TenantInvitationMail(inviteToTenant($tenant)))->render())
        ->toContain('Sie wurden eingeladen');
});

it('refreshes the existing invitation instead of duplicating a re-invite', function () {
    $tenant = Tenant::factory()->create();

    $first = inviteToTenant($tenant);
    $second = inviteToTenant($tenant);

    expect(TenantInvitation::query()->count())->toBe(1)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->token)->not->toBe($first->token);
});

it('signs the accept link on the tenant own domain', function () {
    // Every request is routed by host — ResolveTenantFromHost 404s a host that
    // belongs to no tenant — so a link on the app default host would be dead on
    // any install serving more than one site.
    $tenant = Tenant::factory()->create(['primary_domain' => 'kunde.test']);

    expect(inviteToTenant($tenant)->acceptUrl())->toContain('://kunde.test/_invitation/');
});

it('attaches the invited user with the invited role when they are signed in', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['email' => 'neu@example.test']);

    $invitation = app(TenantInvitations::class)->invite($tenant, 'neu@example.test', TenantUserRole::Admin);

    $this->actingAs($user)
        ->get($invitation->acceptUrl())
        ->assertRedirect();

    expect($user->fresh()->tenantRole($tenant))->toBe(TenantUserRole::Admin)
        ->and($invitation->fresh()->isAccepted())->toBeTrue();
});

it('accepts twice without creating a second membership', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['email' => 'neu@example.test']);
    $invitation = inviteToTenant($tenant);

    $this->actingAs($user)->get($invitation->acceptUrl());
    $this->actingAs($user)->get($invitation->acceptUrl());

    expect($tenant->users()->count())->toBe(1);
});

it('refuses an invitation opened by a different account', function () {
    $tenant = Tenant::factory()->create();
    $invitation = inviteToTenant($tenant);

    $this->actingAs(User::factory()->create(['email' => 'wer-anders@example.test']))
        ->get($invitation->acceptUrl())
        ->assertForbidden();

    expect($invitation->fresh()->isAccepted())->toBeFalse();
});

it('refuses a tampered accept link', function () {
    $tenant = Tenant::factory()->create();
    $invitation = inviteToTenant($tenant);

    $this->actingAs(User::factory()->create(['email' => 'neu@example.test']))
        ->get('/_invitation/'.$invitation->token)
        ->assertForbidden();
});

it('refuses an expired invitation', function () {
    $tenant = Tenant::factory()->create();
    $invitation = inviteToTenant($tenant);

    $url = $invitation->acceptUrl();
    $this->travelTo(Carbon::now()->addDays(30));

    $this->actingAs(User::factory()->create(['email' => 'neu@example.test']))
        ->get($url)
        ->assertForbidden(); // the signature expires with the invitation
});

it('sends a signed-out invitee without an account to the registration page', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'invite-host.test']);
    $invitation = inviteToTenant($tenant);

    $this->get($invitation->acceptUrl())
        ->assertRedirectContains('invitation_token='.$invitation->token);
});

it('sends a signed-out invitee who already has an account to the login page', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => 'invite-host.test']);
    User::factory()->create(['email' => 'neu@example.test']);
    $invitation = inviteToTenant($tenant);

    $this->get($invitation->acceptUrl())
        ->assertRedirectContains(Filament::getDefaultPanel()->getLoginUrl());
});

it('turns away the registration page without an invitation token', function () {
    Filament::setCurrentPanel(Filament::getPanel('panel'));

    Livewire::test(Register::class)
        ->assertRedirect(Filament::getDefaultPanel()->getLoginUrl());
});

it('creates the account for the invited address and attaches it', function () {
    Filament::setCurrentPanel(Filament::getPanel('panel'));

    $tenant = Tenant::factory()->create();
    $invitation = app(TenantInvitations::class)->invite($tenant, 'neu@example.test', TenantUserRole::Admin);

    session()->put(TenantInvitation::SESSION_TOKEN_KEY, $invitation->token);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Neue Redakteurin',
            // A hostile snapshot: the address must come from the invitation.
            'email' => 'angreifer@example.test',
            'password' => 'geheim-genug-123',
            'passwordConfirmation' => 'geheim-genug-123',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'neu@example.test')->first();

    expect($user)->not->toBeNull()
        ->and(User::query()->where('email', 'angreifer@example.test')->exists())->toBeFalse()
        ->and($user->tenantRole($tenant))->toBe(TenantUserRole::Admin)
        ->and($invitation->fresh()->isAccepted())->toBeTrue();
});

it('lets a tenant admin invite from the user list', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');

    Livewire::test(ListUsers::class)
        ->callAction('invite', ['email' => 'kollege@example.test', 'role' => TenantUserRole::Editor->value]);

    expect($tenant->tenantInvitations()->where('email', 'kollege@example.test')->exists())->toBeTrue();
    Mail::assertQueued(TenantInvitationMail::class);
});

it('does not invite somebody who already has access', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');

    Livewire::test(ListUsers::class)
        ->callAction('invite', ['email' => 'editor-a@example.test', 'role' => TenantUserRole::Editor->value]);

    expect($tenant->tenantInvitations()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('lists an open invitation as a row in the users table', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');

    app(TenantInvitations::class)->invite($tenant, 'wartet@example.test', TenantUserRole::Editor);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        // The invited address stands in for the person — there is no account yet.
        ->assertSee('wartet@example.test')
        ->assertSee('Eingeladen, gültig bis')
        // …next to the members, in one list.
        ->assertSee('admin-a@example.test');
});

it('withdraws an invitation from the users table', function () {
    $tenant = actingAsMarketingPanelUser('admin-a@example.test');

    $invitation = app(TenantInvitations::class)->invite($tenant, 'wartet@example.test', TenantUserRole::Editor);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('cancelInvitation')->table('invitation-'.$invitation->getKey()));

    expect(TenantInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});
