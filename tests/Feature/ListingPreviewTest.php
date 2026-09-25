<?php

/*
 * The listing block lists a content type as the viewing user may see it: the
 * live entries — and, while a member previews, every entry, unpublished and
 * scheduled ones included.
 */

use Mmoollllee\Cms\Enums\ContentVisibility;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

it('lists every entry in a member preview, the live ones otherwise', function () {
    $tenant = Tenant::factory()->create(['primary_domain' => '127.0.0.1', 'site_key' => 'marketing']);

    Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'default.page',
        'title' => 'Notizen',
        'path' => '/listing-fixture',
        'visibility' => ContentVisibility::Public,
        'publish_from' => now()->subDay(),
        'blocks' => [[
            'type' => 'section',
            'data' => ['blocks' => [[
                'type' => 'listing',
                'data' => ['active' => true, 'content_type' => 'marketing.note'],
            ]]],
        ]],
    ]);

    foreach (['Live-Notiz' => now()->subDay(), 'Entwurfs-Notiz' => null, 'Geplante Notiz' => now()->addWeek()] as $title => $publishFrom) {
        Content::create([
            'tenant_id' => $tenant->id,
            'content_type' => 'marketing.note',
            'title' => $title,
            'visibility' => ContentVisibility::Public,
            'publish_from' => $publishFrom,
        ]);
    }

    $this->get('http://127.0.0.1/listing-fixture?preview=1')
        ->assertOk()
        ->assertSee('Live-Notiz')
        ->assertDontSee('Entwurfs-Notiz')
        ->assertDontSee('Geplante Notiz');

    $this->actingAs(User::factory()->superadmin()->create());

    $this->get('http://127.0.0.1/listing-fixture?preview=1')
        ->assertOk()
        ->assertSee('Live-Notiz')
        ->assertSee('Entwurfs-Notiz')
        ->assertSee('Geplante Notiz');
});
