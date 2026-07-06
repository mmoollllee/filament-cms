<?php

namespace Mmoollllee\Cms\Support;

use Mmoollllee\Cms\Concerns\Tenant\HasSpamQuestions;
use Mmoollllee\Cms\Support\Livewire\Concerns\WithSpamQuiz;

/**
 * The fallback spam-quiz question set, used when a tenant (and its branding source)
 * configure none. Single source for both the tenant-side
 * {@see HasSpamQuestions} and the form-side
 * {@see WithSpamQuiz} traits.
 */
final class DefaultSpamQuestions
{
    /**
     * @var list<array{question: string, answer: string}>
     */
    public const ALL = [
        ['question' => 'Was ergibt drei plus vier?', 'answer' => '7, sieben'],
        ['question' => 'Welche Farbe hat der Himmel bei klarem Wetter?', 'answer' => 'blau'],
        ['question' => 'Wie viele Beine hat eine Katze?', 'answer' => '4, vier'],
        ['question' => 'Was ergibt zwei mal drei?', 'answer' => '6, sechs'],
        ['question' => 'Nennen Sie die Jahreszeit nach dem Winter.', 'answer' => 'Frühling'],
    ];
}
