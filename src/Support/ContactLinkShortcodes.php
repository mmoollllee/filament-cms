<?php

namespace Mmoollllee\Cms\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Mmoollllee\Cms\CmsServiceProvider;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Spam-protected contact-link shortcodes — [contact_email_link] / [contact_phone_link]
 * plus their RichEditor merge-tag values.
 *
 * Renders the encrypted <x-encrypt-email> / <x-encrypt-phone> components from
 * yannkuesthardt/laravel-spamprotect, fed from the current tenant's resolved contact
 * settings. Registered automatically by {@see CmsServiceProvider}; the encrypt components require the
 * spamprotect package (a dependency of this package) to be installed in the app.
 */
class ContactLinkShortcodes
{
    public static function register(): void
    {
        Shortcodes::register('contact_email_link', fn (array $attrs): string => (string) static::encryptedContactLink('email', $attrs));
        Shortcodes::register('contact_phone_link', fn (array $attrs): string => (string) static::encryptedContactLink('phone', $attrs));
        Shortcodes::registerMergeTagValue('contact_email_link', fn (): HtmlString => static::encryptedContactLink('email'));
        Shortcodes::registerMergeTagValue('contact_phone_link', fn (): HtmlString => static::encryptedContactLink('phone'));
    }

    /**
     * @param  array<string, string>  $attrs
     */
    protected static function encryptedContactLink(string $type, array $attrs = []): HtmlString
    {
        $settingKey = $type === 'email' ? 'contact_email' : 'contact_phone';
        $value = app(CurrentTenant::class)->get()?->resolvedSiteSetting($settingKey) ?? '';

        if (blank($value)) {
            return new HtmlString('');
        }

        $component = $type === 'email' ? 'encrypt-email' : 'encrypt-phone';
        $rendered = Blade::render(
            "<x-{$component} :{$type}=\"\$value\" :class=\"\$class\" />",
            ['value' => $value, 'class' => $attrs['class'] ?? ''],
        );

        return new HtmlString($rendered);
    }
}
