<?php

/*
 * The builder row shows what the "Block-Optionen" dialog holds as small badges —
 * layout presets, header layout, background image, anchor. Options that live in
 * a dialog would otherwise stay invisible until someone opens it.
 */

use Filament\Facades\Filament;
use Livewire\Livewire;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Filament\Support\BlockOptionBadges;
use Mmoollllee\Cms\Models\LayoutPreset;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

function badgePreset(string $scope, string $title): LayoutPreset
{
    return LayoutPreset::query()->create(['scope' => [$scope], 'type' => 'Test', 'title' => $title, 'classes' => 'grid']);
}

it('turns the option values of a block into badges', function () {
    $layout = badgePreset('section', 'Zwei Spalten');
    $header = badgePreset('section-header', 'Schmal');

    $badges = BlockOptionBadges::for([
        'layout_preset_ids' => [$layout->getKey()],
        // Older data stores preset ids as strings.
        'header_preset_ids' => [(string) $header->getKey()],
        'background_image' => 'tenants/demo/bg.jpg',
        'anchor_id' => 'kontakt',
    ]);

    expect(array_column($badges, 'label'))->toBe(['Zwei Spalten', 'Kopf: Schmal', 'Hintergrundbild', '#kontakt'])
        ->and(array_column($badges, 'title'))->toBe(['Layout', 'Header-Layout', 'Hintergrundbild', 'Anker-ID']);
});

it('shows no badges for a block without options', function () {
    expect(BlockOptionBadges::for(['active' => true, 'layout_preset_ids' => [], 'anchor_id' => null, 'heading' => 'h2']))
        ->toBe([]);
});

it('skips preset ids that no longer exist', function () {
    expect(BlockOptionBadges::for(['layout_preset_ids' => [999999]]))->toBe([]);
});

it('renders the badges in the builder row', function () {
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('panel'));
    $tenant = Tenant::where('site_key', 'marketing')->firstOrFail();
    $this->actingAs(User::where('email', 'admin@example.test')->firstOrFail());
    Filament::setTenant($tenant);
    app(CurrentTenant::class)->set($tenant);

    $layout = badgePreset('section', 'Badge-Layout');

    $page = Content::factory()->for($tenant)->create([
        'content_type' => 'default.page',
        'blocks' => [['type' => 'section', 'data' => [
            'active' => true,
            'layout_preset_ids' => [$layout->getKey()],
            'anchor_id' => 'ueber-uns',
            'blocks' => [],
        ]]],
    ]);

    Livewire::test(EditContent::class, ['record' => $page->getKey()])
        ->assertSeeHtml('fi-cms-block-option-badges')
        ->assertSee('Badge-Layout')
        ->assertSee('#ueber-uns');
});
