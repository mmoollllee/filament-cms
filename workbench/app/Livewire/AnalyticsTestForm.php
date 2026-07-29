<?php

namespace Workbench\App\Livewire;

use Mmoollllee\Cms\Support\Livewire\AbstractTenantAwareForm;

/** A named public form, for asserting the analytics half of the base class. */
class AnalyticsTestForm extends AbstractTenantAwareForm
{
    /** Set by the test to exercise a payload with a blank value in it. */
    public ?string $variant = null;

    protected ?string $analyticsName = 'test-form';

    public function submit(): void
    {
        if ($this->trippedHoneypot()) {
            return;
        }

        $this->submitted = true;

        $this->trackConversion(['variant' => $this->variant]);
    }

    public function render(): string
    {
        return <<<'BLADE'
            <form {!! $this->analyticsAttributes() !!}>
                <input type="text" wire:model="website">
            </form>
        BLADE;
    }
}
