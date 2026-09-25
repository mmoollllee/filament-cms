<?php

/*
 * The preview focus: a record without a page of its own (a note, a notice, an
 * offer) previews on the page that embeds it, and its "Vorschau" names it in the
 * URL (?preview_focus=ID). The preview itself stays what it always was — a member
 * sees every entry, unpublished ones included; the focus only decides which entry
 * wins a slot that shows ONE of several (previewFocusFirst()).
 */

use Illuminate\Http\Request;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Mmoollllee\Cms\Filament\Resources\Contents\Pages\EditContent;
use Mmoollllee\Cms\Support\Preview\PreviewMode;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->tenant = actingAsMarketingPanelAdmin();
});

function focusNote(Tenant $tenant, string $title, array $window): Content
{
    return Content::create([
        'tenant_id' => $tenant->id,
        'content_type' => 'marketing.note',
        'title' => $title,
        'visibility' => ContentVisibility::Public,
        ...$window,
    ]);
}

it('names a record without a page of its own in the preview url', function () {
    $note = focusNote($this->tenant, 'Notiz', ['publish_from' => now()->addWeek()]);
    $page = Content::where('tenant_id', $this->tenant->id)->where('path', '/')->firstOrFail();

    $previewHandler = fn (Content $record): string => (string) Livewire::test(EditContent::class, ['record' => $record->getKey()])
        ->instance()
        ->getAction('preview')
        ->getAlpineClickHandler();

    expect($previewHandler($note))->toContain((string) Js::from(route('content.show', ['preview' => 1, 'preview_focus' => $note->getKey()])))
        // A page previews at its own address — nothing to name there.
        ->and($previewHandler($page))->not->toContain('preview_focus');
});

it('leaves what the preview shows untouched', function () {
    $live = focusNote($this->tenant, 'Aktuell', ['publish_from' => now()->subDay()]);
    $expired = focusNote($this->tenant, 'Abgelaufen', ['publish_from' => now()->subYear(), 'publish_until' => now()->subMonth()]);
    $unpublished = focusNote($this->tenant, 'Unveröffentlicht', ['publish_from' => null]);

    $notes = fn (?User $user): array => Content::query()->visibleTo($this->tenant, $user)->ofType('marketing.note')->pluck('id')->all();

    $preview = app(PreviewMode::class);
    $preview->activate();
    $preview->focus($expired->getKey());

    // A member's preview shows every note; the live view stays live.
    expect($notes(auth()->user()))->toEqualCanonicalizing([$live->getKey(), $expired->getKey(), $unpublished->getKey()])
        ->and($notes(null))->toBe([$live->getKey()]);

    $preview->deactivate();
});

it('lets the focused record win a slot that shows one entry', function () {
    $newest = focusNote($this->tenant, 'Neuestes', ['publish_from' => now()->subDay()]);
    $older = focusNote($this->tenant, 'Älteres', ['publish_from' => now()->subWeek()]);
    $scheduled = focusNote($this->tenant, 'Nächste Woche', ['publish_from' => now()->addWeek()]);
    $unpublished = focusNote($this->tenant, 'Entwurf', ['publish_from' => null]);

    $member = auth()->user();

    $slot = fn (): ?Content => Content::query()
        ->visibleTo($this->tenant, $member)
        ->ofType('marketing.note')
        ->previewFocusFirst()
        ->orderByDesc('publish_from')
        ->first();

    expect($slot()->is($newest))->toBeTrue();

    $preview = app(PreviewMode::class);
    $preview->activate();

    // Without a focus every entry competes — the scheduled one is the newest.
    expect($slot()->is($scheduled))->toBeTrue();

    foreach ([$older, $unpublished] as $focused) {
        $preview->focus($focused->getKey());

        expect($slot()->is($focused))->toBeTrue();
    }

    $preview->deactivate();
});

it('reads the focus from the query as a plain record id, and only while previewing', function () {
    $superadmin = User::factory()->superadmin()->create();

    $focusFor = function (string $query, ?User $user): ?int {
        $request = Request::create('http://127.0.0.1/'.$query);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn (): ?User => $user);

        $preview = new PreviewMode;
        $preview->activateFromRequest($request, $this->tenant);

        return $preview->focusedContentId();
    };

    expect($focusFor('?preview=1&preview_focus=42', $superadmin))->toBe(42)
        ->and($focusFor('?preview=1&preview_focus=42abc', $superadmin))->toBeNull()
        ->and($focusFor('?preview=1&preview_focus=-42', $superadmin))->toBeNull()
        ->and($focusFor('?preview=1&preview_focus[]=42', $superadmin))->toBeNull()
        // A guest cannot preview — there is nothing to focus.
        ->and($focusFor('?preview=1&preview_focus=42', null))->toBeNull();
});
