<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mmoollllee\Cms\Support\Livewire\Concerns\WithSpamQuiz;
use Mmoollllee\Cms\Support\Tenancy\CurrentTenant;
use Workbench\App\Models\Tenant;

uses(RefreshDatabase::class);

/**
 * A bare host for the trait — exercises its pure logic without a full Livewire runtime.
 */
function spamQuizHost(): object
{
    return new class
    {
        use WithSpamQuiz;

        public function isValid(): bool
        {
            return $this->isValidSpamAnswer();
        }
    };
}

it('falls back to the package default questions when no tenant is resolved', function () {
    $host = spamQuizHost();

    expect($host->spamQuestions())->not->toBeEmpty()
        ->and($host->spamQuizQuestion())->toBe('Was ergibt drei plus vier?');
});

it('accepts a correct, normalized answer', function () {
    $host = spamQuizHost();
    $host->quizIndex = 0;

    $host->quizAnswer = '  Sieben ';
    expect($host->isValid())->toBeTrue();

    $host->quizAnswer = '7';
    expect($host->isValid())->toBeTrue();
});

it('rejects a wrong answer', function () {
    $host = spamQuizHost();
    $host->quizIndex = 0;
    $host->quizAnswer = 'acht';

    expect($host->isValid())->toBeFalse();
});

it('uses the tenant resolvedSpamQuestions when available', function () {
    $tenant = Tenant::factory()->create([
        'spam_questions' => [
            ['question' => 'Eigene Frage?', 'answer' => 'eigene'],
        ],
    ]);
    app(CurrentTenant::class)->set($tenant);

    $host = spamQuizHost();
    $host->initSpamQuiz();

    expect($host->spamQuizQuestion())->toBe('Eigene Frage?');

    $host->quizAnswer = 'EIGENE';
    expect($host->isValid())->toBeTrue();
});
