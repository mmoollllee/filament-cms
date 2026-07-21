<?php

namespace Mmoollllee\Cms\Support\Preview;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Concerns\HasDraft;

/**
 * Capability checks around the {@see HasDraft} trait.
 *
 * The engine's edit pages and tables run against app-owned models; installs
 * that have not adopted the trait + `draft` column yet must keep working with
 * the classic save-only flow. Every draft UI element gates on these checks.
 */
final class Drafts
{
    /** @var array<class-string, bool> */
    private static array $supportedMemo = [];

    /** Whether the model (class or instance) has adopted {@see HasDraft}. */
    public static function supported(object|string|null $model): bool
    {
        if ($model === null) {
            return false;
        }

        $class = is_object($model) ? $model::class : $model;

        // class_uses_recursive() walks the full parent/trait tree per call —
        // memoized because tables ask per row. Trait composition is immutable
        // per process, so the memo is also Octane-safe.
        return self::$supportedMemo[$class] ??= in_array(HasDraft::class, class_uses_recursive($class), true);
    }

    /** Whether the record supports drafts AND currently has one stashed. */
    public static function pending(?object $record): bool
    {
        return $record !== null
            && static::supported($record)
            && $record->hasDraft();
    }

    /**
     * The shared "Entwurf" badge column for content/fragment tables — one
     * definition so both tables present the same indicator.
     */
    public static function tableBadgeColumn(?string $modelClass): TextColumn
    {
        return TextColumn::make('draft')
            ->label('Entwurf')
            ->badge()
            ->color('warning')
            ->state(fn (Model $record): ?string => static::pending($record) ? 'Entwurf' : null)
            ->tooltip('Gespeicherter Entwurf — noch nicht angewendet.')
            ->visible(static::supported($modelClass));
    }
}
