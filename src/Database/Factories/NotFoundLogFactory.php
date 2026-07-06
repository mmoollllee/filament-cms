<?php

namespace Mmoollllee\Cms\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Models\NotFoundLog;

/**
 * @extends Factory<NotFoundLog>
 */
class NotFoundLogFactory extends Factory
{
    protected $model = NotFoundLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Cms::tenantModel()::factory(),
            'path' => '/'.fake()->unique()->slug(),
            'hits' => fake()->numberBetween(1, 25),
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now(),
        ];
    }
}
