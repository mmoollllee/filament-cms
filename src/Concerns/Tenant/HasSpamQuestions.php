<?php

namespace Mmoollllee\Cms\Concerns\Tenant;

use Mmoollllee\Cms\Contracts\Tenant;
use Mmoollllee\Cms\Support\DefaultSpamQuestions;
use Mmoollllee\Cms\Support\Livewire\Concerns\WithSpamQuiz;

/**
 * Tenant-configurable spam quiz questions, resolved with the branding-tenant cascade.
 *
 * Self-wires the `spam_questions` JSON column (fillable + array cast) via Eloquent's
 * trait-initializer hook, so a tenant model only needs `use HasSpamQuestions;` plus a
 * migration adding the column. Pair with the public-form
 * {@see WithSpamQuiz} trait.
 *
 * Requires the host model to provide resolvedSiteSetting() (the branding cascade) — i.e.
 * a {@see Tenant}.
 */
trait HasSpamQuestions
{
    /**
     * Fallback question set when a tenant (and its branding source) define none.
     *
     * @var list<array{question: string, answer: string}>
     */
    public const DEFAULT_SPAM_QUESTIONS = DefaultSpamQuestions::ALL;

    public function initializeHasSpamQuestions(): void
    {
        $this->mergeFillable(['spam_questions']);
        $this->mergeCasts(['spam_questions' => 'array']);
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function resolvedSpamQuestions(): array
    {
        $questions = static::normalizeSpamQuestions($this->resolvedSiteSetting('spam_questions', []));

        return $questions !== [] ? $questions : static::DEFAULT_SPAM_QUESTIONS;
    }

    /**
     * Keep only well-formed {question, answer} entries.
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function normalizeSpamQuestions(mixed $questions): array
    {
        if (! is_array($questions)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $item): ?array {
            if (! is_array($item)) {
                return null;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            return ($question !== '' && $answer !== '') ? ['question' => $question, 'answer' => $answer] : null;
        }, $questions)));
    }
}
