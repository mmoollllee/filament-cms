<?php

/**
 * Conversion tracking on the public-form base class: a named form marks its
 * <form> tag so <x-filami::events /> can report a start, and reports its own
 * completion on success.
 *
 * The rules worth pinning are about what does NOT get counted — a bot, a
 * failed validation, an unnamed form — and about what never reaches the
 * payload. Umami stores event properties next to the pageview, so anything
 * identifying the sender that slips in here is a privacy incident, not a bug.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Workbench\App\Livewire\AnalyticsTestForm;

uses(RefreshDatabase::class);

it('marks the form so the tracker can report a start', function () {
    Livewire::test(AnalyticsTestForm::class)
        ->assertSeeHtml('data-filami-form="test-form"');
});

it('reports a completion on a successful submission', function () {
    // $variant is unset here, so this also pins the blank-value rule: a null
    // property is dropped rather than recorded as an empty answer in Umami.
    Livewire::test(AnalyticsTestForm::class)
        ->call('submit')
        ->assertDispatched('filami-track', name: 'test-form-submit', data: []);
});

it('drops a blank value rather than recording an empty answer', function () {
    Livewire::test(AnalyticsTestForm::class)
        ->set('variant', '')
        ->call('submit')
        ->assertDispatched('filami-track', data: []);
});

it('carries the categories the form passes along', function () {
    Livewire::test(AnalyticsTestForm::class)
        ->set('variant', 'sidebar')
        ->call('submit')
        ->assertDispatched('filami-track', data: ['variant' => 'sidebar']);
});

it('counts no conversion for a bot', function () {
    Livewire::test(AnalyticsTestForm::class)
        ->set('website', 'http://spam.example')
        ->call('submit')
        // The bot still sees the thank-you banner; nothing was sent, so
        // nothing is counted.
        ->assertSet('submitted', true)
        ->assertNotDispatched('filami-track');
});

it('measures nothing for a form that did not opt in', function () {
    // Forms predating this must not start reporting an unnamed event after an
    // upgrade — $analyticsName defaults to null. tenantFormHost() is the shared
    // unnamed concretion from the base-class suite.
    expect((string) tenantFormHost()->analyticsAttributes())->toBe('')
        ->and(tenantFormHost()->analyticsName())->toBeNull();
});
