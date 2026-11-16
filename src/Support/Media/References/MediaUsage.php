<?php

namespace Mmoollllee\Cms\Support\Media\References;

use Illuminate\Database\Eloquent\Model;

/**
 * One record referencing one media-library item.
 *
 * `applied` and `drafted` say WHERE in the record the reference sits: in what
 * visitors get, in the unapplied draft, or both. `live` and `scheduled` say
 * whether the record itself is visible to visitors now, or will be once its
 * publishing window opens. A use is public only when both line up — and the
 * reference does not sit only in a block switched off (`hidden`).
 *
 * `owner` identifies the record — type, key, state — without the stored
 * content it was found in, which stays out of memory.
 */
final class MediaUsage
{
    public function __construct(
        public readonly int $itemId,
        public readonly Model $owner,
        public readonly string $label,
        public readonly bool $applied,
        public readonly bool $drafted,
        public readonly bool $live,
        public readonly bool $scheduled = false,
        /** What the reference is on the record, where that helps ("Logo"). */
        public readonly ?string $detail = null,
        /** Applied only in blocks switched off: kept, not shown. */
        public readonly bool $hidden = false,
    ) {}

    /** Visitors see the item through this record right now. */
    public function isPublic(): bool
    {
        return $this->applied && $this->live && ! $this->hidden;
    }

    /** Visitors will see the item through this record once its window opens. */
    public function isUpcoming(): bool
    {
        return $this->applied && $this->scheduled && ! $this->hidden;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isPublic() => 'veröffentlicht',
            $this->isUpcoming() => 'geplant',
            $this->applied && $this->hidden => 'ausgeblendet',
            $this->applied => 'nicht veröffentlicht',
            default => 'nur im Entwurf',
        };
    }

    /**
     * As plain values, for a cache that must not hold objects.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'itemId' => $this->itemId,
            'owner' => [$this->owner::class, $this->owner->getAttributes()],
            'label' => $this->label,
            'applied' => $this->applied,
            'drafted' => $this->drafted,
            'live' => $this->live,
            'scheduled' => $this->scheduled,
            'detail' => $this->detail,
            'hidden' => $this->hidden,
        ];
    }

    /**
     * @param  array<string, mixed>  $usage  {@see toArray()}
     */
    public static function fromArray(array $usage): self
    {
        [$class, $attributes] = $usage['owner'];

        $owner = (new $class)->newInstance([], true);
        $owner->setRawAttributes($attributes, true);

        return new self(
            itemId: $usage['itemId'],
            owner: $owner,
            label: $usage['label'],
            applied: $usage['applied'],
            drafted: $usage['drafted'],
            live: $usage['live'],
            scheduled: $usage['scheduled'],
            detail: $usage['detail'],
            hidden: $usage['hidden'],
        );
    }
}
