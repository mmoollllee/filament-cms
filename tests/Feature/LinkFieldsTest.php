<?php

/*
 * LinkFields (resource-form link group) mirrors the link-picker modal's
 * options; PayloadLink renders the stored group as anchor attributes. The
 * derived field names are contract — pernes/münch payloads store them.
 */

use DefStudio\SearchableInput\Forms\Components\SearchableInput;
use Filament\Schemas\Components\Section;
use Mmoollllee\Cms\Fields\LinkFields;
use Mmoollllee\Cms\Support\Content\PayloadLink;

it('derives all field names from the base state path', function () {
    $fields = LinkFields::make('payload.link')->toArray();

    $names = collect($fields)
        ->flatMap(fn ($component) => $component instanceof Section
            ? collect($component->getDefaultChildComponents())->map(fn ($child) => $child->getName())
            : [$component->getName()])
        ->all();

    expect($names)->toBe([
        'payload.link',
        'payload.link_label',
        'payload.link_wire_navigate',
        'payload.link_title',
        'payload.link_class',
        'payload.link_rel',
    ]);
});

it('offers the same option set as the link-picker modal, with autocomplete on the url', function () {
    $fields = LinkFields::make()->toArray();

    expect($fields[0])->toBeInstanceOf(SearchableInput::class)
        ->and($fields[0]->getFieldWrapperView())->toBe('cms-link-suggestions-wrapper')
        ->and($fields[3])->toBeInstanceOf(Section::class);
});

it('supports the FieldKit composition API', function () {
    $fields = LinkFields::make('cta_url')->without('advanced', 'wire_navigate')->toArray();

    expect($fields)->toHaveCount(2)
        ->and($fields[0]->getName())->toBe('cta_url')
        ->and($fields[1]->getName())->toBe('cta_url_label');
});

it('adapts single fields via configure() without forking the kit', function () {
    $fields = LinkFields::make('payload.link')
        ->configure('url', fn ($field) => $field->placeholder('/projektpfad …'))
        ->toArray();

    expect($fields[0]->getPlaceholder())->toBe('/projektpfad …');
});

it('renders stored values as anchor attributes', function () {
    $payload = [
        'link' => '/mietpark/buehne',
        'link_label' => 'Zur Maschine',
        'link_title' => 'Details ansehen',
        'link_class' => 'btn-surface',
        'link_rel' => 'nofollow',
        'link_wire_navigate' => true,
    ];

    $link = PayloadLink::from($payload);

    expect($link->hasUrl())->toBeTrue()
        ->and($link->labelOr('Mehr erfahren'))->toBe('Zur Maschine');

    $html = (string) $link->attributes(['class' => 'btn btn-sm']);

    expect($html)->toContain('href="/mietpark/buehne"')
        ->toContain('title="Details ansehen"')
        ->toContain('rel="nofollow"')
        ->toContain('wire:navigate')
        ->toContain('btn btn-sm')
        ->toContain('btn-surface');
});

it('omits empty attributes and can exclude the custom classes', function () {
    $link = PayloadLink::from(['link' => '/kontakt']);

    expect($link->labelOr('Mehr erfahren'))->toBe('Mehr erfahren')
        ->and((string) $link->attributes())->toBe('href="/kontakt"');

    $thumb = PayloadLink::from(['link' => '/kontakt', 'link_class' => 'btn'])
        ->attributes(['class' => 'group block'], withClass: false);

    expect((string) $thumb)->not->toContain('btn')
        ->and((string) $thumb)->toContain('group block');
});

it('reports no url for an empty payload', function () {
    expect(PayloadLink::from(null)->hasUrl())->toBeFalse()
        ->and(PayloadLink::from([])->hasUrl())->toBeFalse();
});
