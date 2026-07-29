<?php

/*
 * Editorial mailto:/tel: links are rewritten into spamprotect components that
 * hide the address from scrapers.
 *
 * Two properties matter and pull against each other. The address must not
 * survive anywhere in the markup — which is exactly why these links are also
 * the ones filami's delegated listener cannot recognise by href, so they carry
 * a data-filami-event attribute instead. Deliberately NOT Umami's own
 * data-umami-event: its tracker preventDefault()s those anchor clicks and then
 * forces location.href, which on an href="#" link races the handler decrypting
 * the address. The attribute may name the event and nothing else — putting the
 * address in as event data would write it straight back into the page.
 *
 * And the rewrite runs editorial text through Blade::render(), which COMPILES
 * its template argument — so the label must never reach it as anything but
 * data.
 */

use Mmoollllee\Cms\Support\SpamprotectHtml;

beforeEach(function () {
    config()->set('filami.events.links', true);
    config()->set('filami.events.phone_event', 'phone-click');
    config()->set('filami.events.email_event', 'email-click');
});

it('hides the address and marks the link for umami', function () {
    $html = SpamprotectHtml::protectEmails('<p><a href="mailto:info@acme.test">Schreiben Sie uns</a></p>');

    expect($html)
        ->toContain('data-spamprotect-token=')
        ->toContain('data-filami-event="email-click"')
        ->toContain('Schreiben Sie uns')
        // The whole point of the rewrite.
        ->not->toContain('mailto:info@acme.test')
        ->not->toContain('info@acme.test');
});

it('marks phone links with their own event', function () {
    $html = SpamprotectHtml::protectEmails('<p><a href="tel:+4970012345">Anrufen</a></p>');

    expect($html)
        ->toContain('data-filami-event="phone-click"')
        ->not->toContain('tel:+4970012345');
});

it('never uses the attribute umami hijacks clicks for', function () {
    // Umami attaches a capture-phase handler to [data-umami-event] anchors that
    // calls preventDefault() and then assigns location.href. On these href="#"
    // links that competes with the spamprotect handler opening the mailto:.
    expect(SpamprotectHtml::protectEmails('<a href="mailto:info@acme.test">Mail</a>'))
        ->not->toContain('data-umami-event');
});

it('honours renamed events', function () {
    config()->set('filami.events.email_event', 'mail-kontakt');

    expect(SpamprotectHtml::protectEmails('<a href="mailto:info@acme.test">Mail</a>'))
        ->toContain('data-filami-event="mail-kontakt"');
});

it('leaves the links unmeasured when link events are switched off', function () {
    config()->set('filami.events.links', false);

    $html = SpamprotectHtml::protectEmails('<a href="mailto:info@acme.test">Mail</a>');

    expect($html)
        ->not->toContain('data-filami-event')
        // Still protected — analytics is not what this rewrite is for.
        ->toContain('data-spamprotect-token=');
});

it('never lets an editorial label reach blade as template code', function () {
    // Blade compiles its first argument, and e() leaves {{ }} untouched — a
    // label concatenated into the template would execute here. Any tenant
    // editor can type this into the link picker.
    $html = SpamprotectHtml::protectEmails('<a href="mailto:info@acme.test">{{ phpversion() }}</a>');

    expect($html)->toContain('phpversion()')
        ->not->toContain(PHP_VERSION);
});

it('leaves other links alone', function () {
    $html = '<p><a href="https://acme.test/kontakt">Kontakt</a></p>';

    expect(SpamprotectHtml::protectEmails($html))->toBe($html);
});
