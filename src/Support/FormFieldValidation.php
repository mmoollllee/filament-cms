<?php

namespace Mmoollllee\Cms\Support;

use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Validation\ValidationException;

/**
 * Moves a ValidationException raised inside a MODEL onto the form's state path.
 *
 * The content saving hook guards the (tenant_id, path) index for every writer there is —
 * the panel, the Duplizieren action, imports, seeders, console commands — so it throws
 * under the model's own attribute name, `path`. Filament renders errors by state path,
 * `data.path`, and an error keyed to nothing the form owns is simply not drawn.
 *
 * Left alone that is the worst outcome of the three: the panel opts into database
 * transactions, so the editor presses Speichern, the write rolls back, and the screen
 * says nothing at all. A silent no-op is harder to act on than the 500 this guard
 * replaced.
 */
class FormFieldValidation
{
    /**
     * Re-key every message onto $statePath, leaving already-prefixed keys alone.
     */
    public static function rekey(ValidationException $exception, string $statePath): ValidationException
    {
        if ($statePath === '') {
            return $exception;
        }

        $messages = [];

        foreach ($exception->errors() as $key => $bag) {
            $prefixed = str_starts_with($key, $statePath.'.') ? $key : $statePath.'.'.$key;

            $messages[$prefixed] = $bag;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * The state path of a page's form, falling back to Filament's own default for a page
     * that has no form schema by that name.
     */
    public static function statePathOf(HasSchemas $page, string $schema = 'form'): string
    {
        return $page->getSchema($schema)?->getStatePath() ?: 'data';
    }
}
