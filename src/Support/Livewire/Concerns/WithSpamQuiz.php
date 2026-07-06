<?php

namespace Mmoollllee\Cms\Support\Livewire\Concerns;

use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Mmoollllee\Cms\Concerns\Tenant\HasSpamQuestions;
use Mmoollllee\Cms\Support\DefaultSpamQuestions;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;

/**
 * Rotating spam quiz for public forms. Picks a random, tenant-defined question on
 * mount and validates the answer on submit.
 *
 * The question set is resolved with the branding-tenant cascade when the tenant model
 * provides resolvedSpamQuestions() (see the {@see HasSpamQuestions}
 * trait); otherwise it falls back to {@see static::defaultSpamQuestions()}. The trait is
 * decoupled from any concrete model so projects can adopt it before wiring spam settings.
 */
trait WithSpamQuiz
{
    public string $quizAnswer = '';

    /** Index into the resolved question list — locked so the client can't swap it. */
    #[Locked]
    public int $quizIndex = 0;

    /** @var list<array{question: string, answer: string}>|null Per-request memo. */
    private ?array $spamQuestionsCache = null;

    /** Pick a fresh random question. Call this from the component's mount(). */
    public function initSpamQuiz(): void
    {
        $count = count($this->spamQuestions());
        $this->quizIndex = $count > 1 ? random_int(0, $count - 1) : 0;
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function spamQuestions(): array
    {
        if ($this->spamQuestionsCache !== null) {
            return $this->spamQuestionsCache;
        }

        $tenant = app(CurrentTenant::class)->get();

        if ($tenant !== null && method_exists($tenant, 'resolvedSpamQuestions')) {
            $questions = $tenant->resolvedSpamQuestions();

            if ($questions !== []) {
                return $this->spamQuestionsCache = $questions;
            }
        }

        return $this->spamQuestionsCache = static::defaultSpamQuestions();
    }

    public function spamQuizQuestion(): string
    {
        $questions = $this->spamQuestions();
        $index = isset($questions[$this->quizIndex]) ? $this->quizIndex : 0;

        return $questions[$index]['question'] ?? '';
    }

    public function validateSpamQuiz(): void
    {
        if (! $this->isValidSpamAnswer()) {
            throw ValidationException::withMessages([
                'quizAnswer' => 'Die Antwort auf die Sicherheitsfrage ist leider nicht korrekt.',
            ]);
        }
    }

    protected function isValidSpamAnswer(): bool
    {
        $questions = $this->spamQuestions();
        $expected = $questions[$this->quizIndex]['answer'] ?? ($questions[0]['answer'] ?? '');

        $normalize = static fn (string $value): string => (string) preg_replace('/[\s\-]+/', '', mb_strtolower(trim($value)));
        $given = $normalize($this->quizAnswer);

        return $given !== '' && in_array($given, array_map($normalize, explode(',', $expected)), true);
    }

    /**
     * Package fallback used when the tenant model exposes no resolvedSpamQuestions().
     *
     * @return list<array{question: string, answer: string}>
     */
    protected static function defaultSpamQuestions(): array
    {
        return DefaultSpamQuestions::ALL;
    }
}
