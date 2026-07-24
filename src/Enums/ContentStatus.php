<?php

namespace Mmoollllee\Cms\Enums;

use Carbon\CarbonInterface;
use Mmoollllee\Cms\Concerns\HasDraft;

/**
 * The publication state derived from a content's publishing window — never a
 * stored column. {@see self::forWindow()} is the single derivation used by the
 * model trait (HasPublishingStatus) and the form badge (PublishingFields).
 *
 * The `Draft` case is labeled "Unveröffentlicht": the word "Entwurf" belongs
 * exclusively to the draft STASH (unapplied changes, {@see HasDraft})
 * — one word per concept, so editors cannot confuse "park my changes" with
 * "take the page offline". The case name/value stay `draft` for BC (the value
 * never hits the database, but consumer apps may reference it).
 */
enum ContentStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Expired = 'expired';

    /**
     * Derive the status from a publishing window.
     */
    public static function forWindow(?CarbonInterface $publishFrom, ?CarbonInterface $publishUntil): self
    {
        if ($publishFrom === null) {
            return self::Draft;
        }

        if ($publishFrom->isFuture()) {
            return self::Scheduled;
        }

        if ($publishUntil !== null && $publishUntil->isPast()) {
            return self::Expired;
        }

        return self::Published;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Draft->value => 'Unveröffentlicht',
            self::Published->value => 'Veröffentlicht',
            self::Scheduled->value => 'Geplant',
            self::Expired->value => 'Abgelaufen',
        ];
    }

    public function label(): string
    {
        return self::options()[$this->value];
    }

    /** Shared badge color (resource table + form status badge). */
    public function color(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Scheduled => 'info',
            self::Expired => 'danger',
            self::Draft => 'gray',
        };
    }
}
