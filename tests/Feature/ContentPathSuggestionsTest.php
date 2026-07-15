<?php

/*
 * The link-field autocomplete (link picker, button groups, consumer link
 * fields): suggestions come from the current tenant's routable contents and
 * carry title + path data — the styled two-line dropdown renders from that
 * data, so its shape is contract, not detail.
 */

use DefStudio\SearchableInput\Forms\Components\SearchableInput;
use Filament\Schemas\Schema;
use Mmoollllee\Cms\Filament\Forms\ContentPathSuggestions;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * getSearchResultsForJs() consults the container (isDisabled()), which fields
 * only have inside a schema — give the bare factory product a minimal one.
 */
function pathSuggestionsInput(SearchableInput $input): SearchableInput
{
    $input->container(Schema::make());

    return $input;
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['site_key' => 'default', 'primary_domain' => 'suggest.test']);
    app(CurrentTenant::class)->set($this->tenant);

    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontakt',
        'path' => '/kontakt',
    ]);

    Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Leistungen',
        'path' => '/leistungen',
    ]);
});

it('suggests tenant contents by path fragment, carrying title + path data for the dropdown', function () {
    // The wire payload the Alpine dropdown consumes: value fills the field,
    // data.title/data.path render the two suggestion lines.
    $results = pathSuggestionsInput(ContentPathSuggestions::makeHrefInput())->getSearchResultsForJs('kont');

    expect($results)->toHaveCount(1)
        ->and($results[0])->toBe([
            'value' => '/kontakt',
            'label' => 'Kontakt — /kontakt',
            'data' => ['title' => 'Kontakt', 'path' => '/kontakt'],
        ]);
});

it('matches by title as well and fills the href with the path', function () {
    $results = pathSuggestionsInput(ContentPathSuggestions::makeHrefInput())->getSearchResultsForJs('Leistung');

    expect($results)->toHaveCount(1)
        ->and($results[0]['value'])->toBe('/leistungen');
});

it('suggests titles for label inputs, carrying the path for the sibling href autofill', function () {
    $results = pathSuggestionsInput(ContentPathSuggestions::makeLabelInput())->getSearchResultsForJs('kontakt');

    expect($results)->toHaveCount(1)
        ->and($results[0]['value'])->toBe('Kontakt')
        ->and($results[0]['data']['path'])->toBe('/kontakt');
});

it('never suggests other tenants or unroutable contents', function () {
    $other = Tenant::factory()->create(['site_key' => 'other', 'primary_domain' => 'other.test']);

    Content::create([
        'tenant_id' => $other->id,
        'content_type' => 'default.page',
        'title' => 'Fremder Kontakt',
        'path' => '/fremder-kontakt',
    ]);

    // Unroutable (no path) — e.g. teaser-only entries. The path pipeline
    // auto-generates one on create, so null it below the model layer.
    $unroutable = Content::create([
        'tenant_id' => $this->tenant->id,
        'content_type' => 'default.page',
        'title' => 'Kontaktloser Eintrag',
    ]);

    Content::query()->whereKey($unroutable->getKey())->update(['path' => null]);

    $paths = collect(pathSuggestionsInput(ContentPathSuggestions::makeHrefInput())->getSearchResultsForJs('kontakt'))
        ->pluck('value');

    expect($paths->all())->toBe(['/kontakt']);
});

it('returns no suggestions without a current tenant', function () {
    app(CurrentTenant::class)->forget();

    expect(pathSuggestionsInput(ContentPathSuggestions::makeHrefInput())->getSearchResultsForJs('kontakt'))->toBe([]);
});

it('renders through the styled cms suggestion wrapper', function () {
    expect(ContentPathSuggestions::makeHrefInput()->getFieldWrapperView())->toBe('cms-link-suggestions-wrapper')
        ->and(ContentPathSuggestions::makeLabelInput()->getFieldWrapperView())->toBe('cms-link-suggestions-wrapper');
});
