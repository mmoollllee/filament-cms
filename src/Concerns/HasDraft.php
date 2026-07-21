<?php

namespace Mmoollllee\Cms\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Mmoollllee\Cms\Support\Preview\PreviewMode;

/**
 * Unapplied panel changes ("Entwurf") for Content and Fragment models.
 *
 * "Entwurf speichern" stashes the complete form state into the `draft` JSON
 * column while the live columns keep serving the applied version; "Änderungen
 * anwenden" writes the form state to the live columns and clears the stash.
 * While {@see PreviewMode} is active (logged-in tenant member on the frontend),
 * every model instance overlays its draft on retrieval — so pending changes are
 * visible wherever the record renders (own page, listings, fragments, onepager)
 * without any call-site changes.
 *
 * Persistence is deliberately COLUMN-TARGETED: stashDraft()/discardDraft()
 * update only the `draft` column via the query builder instead of a full
 * save(). A full save would persist ANY other dirty attribute — and during a
 * preview-flagged panel Livewire request the retrieved-overlay has just filled
 * the live attributes with draft values, so "Entwurf verwerfen" would apply
 * the very draft it should discard. For the same reason overlayDraft() calls
 * syncOriginal(): an overlaid instance must never look dirty to anything that
 * saves it later.
 *
 * The host model needs a nullable `draft` JSON column (see the
 * add_draft_column reconcile migration) and its editable attributes in
 * `$fillable` — the overlay applies the stash via `fill()`, so foreign form
 * keys are dropped exactly like on a real save.
 */
trait HasDraft
{
    /** Decoded draft-envelope memo — the array cast would re-json_decode per access. */
    protected ?string $draftEnvelopeRaw = null;

    /** @var array<string, mixed>|null */
    protected ?array $draftEnvelopeMemo = null;

    public static function bootHasDraft(): void
    {
        static::retrieved(function (Model $model): void {
            if (app(PreviewMode::class)->active()) {
                $model->overlayDraft();
            }
        });
    }

    public function initializeHasDraft(): void
    {
        $this->mergeCasts(['draft' => 'array']);
    }

    public function hasDraft(): bool
    {
        return $this->draftData() !== [];
    }

    /**
     * The stashed form state (attribute map), or [] when no draft exists.
     *
     * @return array<string, mixed>
     */
    public function draftData(): array
    {
        $data = data_get($this->draftEnvelope(), 'data');

        return is_array($data) ? $data : [];
    }

    public function draftSavedAt(): ?CarbonInterface
    {
        $savedAt = data_get($this->draftEnvelope(), 'saved_at');

        if (! is_string($savedAt) || $savedAt === '') {
            return null;
        }

        // A draft can be written by imports/consumer code — a malformed
        // timestamp must not 500 the edit page that could discard it.
        return rescue(fn (): CarbonInterface => Carbon::parse($savedAt), report: false);
    }

    /**
     * Stash form state as the pending draft WITHOUT touching the live columns.
     * Writes only the `draft` column (no model events, no cache invalidation —
     * the served content did not change).
     *
     * @param  array<string, mixed>  $data
     */
    public function stashDraft(array $data): void
    {
        $this->draft = [
            'data' => $data,
            'saved_at' => now()->toIso8601String(),
        ];

        $this->persistDraftColumn();
    }

    /** Drop the pending draft; the applied (live) values stay as they are. */
    public function discardDraft(): void
    {
        $this->draft = null;

        $this->persistDraftColumn();
    }

    /**
     * Overlay the stashed draft onto this in-memory instance (never saved).
     * fill() respects $fillable, so the stash cannot touch guarded attributes;
     * syncOriginal() keeps the instance clean so a later save() cannot
     * accidentally persist the overlay.
     */
    public function overlayDraft(): static
    {
        if (! $this->hasDraft()) {
            return $this;
        }

        $this->fill($this->draftData());

        $this->syncOriginal();

        return $this;
    }

    /**
     * Write the current `draft` attribute to the database — and nothing else.
     */
    protected function persistDraftColumn(): void
    {
        $this->newModelQuery()
            ->whereKey($this->getKey())
            ->update(['draft' => $this->getAttributes()['draft'] ?? null]);

        $this->syncOriginalAttribute('draft');
    }

    /**
     * The decoded draft envelope ({data, saved_at}), memoized per raw value.
     *
     * @return array<string, mixed>
     */
    protected function draftEnvelope(): array
    {
        $raw = $this->getAttributes()['draft'] ?? null;

        if ($raw === null) {
            return [];
        }

        if (! is_string($raw)) {
            return is_array($raw) ? $raw : [];
        }

        if ($this->draftEnvelopeRaw !== $raw) {
            $decoded = json_decode($raw, true);

            $this->draftEnvelopeRaw = $raw;
            $this->draftEnvelopeMemo = is_array($decoded) ? $decoded : [];
        }

        return $this->draftEnvelopeMemo ?? [];
    }
}
