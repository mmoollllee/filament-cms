<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Enums\ContentVisibility;
use Workbench\App\Models\Content;
use Workbench\App\Models\Tenant;

/**
 * @extends Factory<Content>
 */
class ContentFactory extends Factory
{
    protected $model = Content::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(3);
        $slug = Str::slug($title);

        return [
            'tenant_id' => Tenant::factory(),
            'content_type' => 'default.page',
            'title' => $title,
            'slug' => $slug,
            'visibility' => ContentVisibility::Public,
            'publish_from' => now()->subDay(),
            'publish_until' => null,
            'blocks' => [
                [
                    'type' => 'section',
                    'data' => [
                        'blocks' => [
                            [
                                'type' => 'text',
                                'data' => [
                                    'heading' => $title,
                                    'content' => fake()->paragraph(),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'payload' => [],
            'references' => [],
            'meta' => [],
            'sort' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'publish_from' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'publish_from' => now()->addDay(),
        ]);
    }

    public function membersOnly(): static
    {
        return $this->state(fn (): array => [
            'visibility' => ContentVisibility::Members,
        ]);
    }
}
