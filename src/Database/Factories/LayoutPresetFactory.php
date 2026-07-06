<?php

namespace Mmoollllee\Cms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mmoollllee\Cms\Models\LayoutPreset;

/**
 * @extends Factory<LayoutPreset>
 */
class LayoutPresetFactory extends Factory
{
    protected $model = LayoutPreset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(2, true),
            'scope' => [fake()->randomElement(['content', 'section', 'section-child'])],
            'classes' => fake()->randomElement(['md:grid-cols-2 gap-5', 'col-span-full', 'md:grid-cols-3 gap-4']),
        ];
    }

    public function forContent(): static
    {
        return $this->state(fn (): array => ['scope' => ['content']]);
    }

    public function forSection(): static
    {
        return $this->state(fn (): array => ['scope' => ['section']]);
    }

    public function forSectionChild(): static
    {
        return $this->state(fn (): array => ['scope' => ['section-child']]);
    }
}
