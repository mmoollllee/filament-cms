<?php

namespace Mmoollllee\Cms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Enums\RedirectOrigin;
use Mmoollllee\Cms\Models\Redirect;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    protected $model = Redirect::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Cms::tenantModel()::factory(),
            'from_path' => '/'.fake()->unique()->slug(),
            'to_url' => '/'.fake()->slug(),
            'to_content_id' => null,
            'status_code' => 301,
            'is_active' => true,
            'origin' => RedirectOrigin::Manual,
            'hits' => 0,
        ];
    }

    public function automatic(): static
    {
        return $this->state(fn (): array => [
            'origin' => RedirectOrigin::Automatic,
            'status_code' => 302,
            'is_active' => true,
        ]);
    }

    public function suggested(): static
    {
        return $this->state(fn (): array => [
            'origin' => RedirectOrigin::Suggested,
            'is_active' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
