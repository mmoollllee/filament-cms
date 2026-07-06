<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Mmoollllee\Cms\Enums\TenantVisibility;
use Workbench\App\Models\Tenant;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $siteKey = Str::slug($name);

        return [
            'name' => $name,
            'site_key' => $siteKey,
            'primary_domain' => "{$siteKey}.test",
            'visibility' => TenantVisibility::Public,
            'brand_name' => $name,
            'brand_claim' => fake()->sentence(3),
            'default_locale' => 'de',
            'timezone' => 'Europe/Berlin',
            'company_name' => null,
            'legal_name' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'street' => null,
            'postal_code' => null,
            'city' => null,
            'country' => null,
            'footer_text' => null,
            'social_links' => null,
            'default_seo_title' => null,
            'default_seo_description' => null,
            'default_og_image_path' => null,
            'imprint_data' => null,
            'privacy_data' => null,
        ];
    }

    public function withSiteSettings(): static
    {
        return $this->state(fn (): array => [
            'company_name' => fake()->company(),
            'legal_name' => fake()->company().' GmbH',
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => fake()->phoneNumber(),
            'street' => fake()->streetAddress(),
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'country' => 'Deutschland',
            'footer_text' => fake()->sentence(),
            'social_links' => [
                [
                    'network' => 'linkedin',
                    'url' => fake()->url(),
                ],
            ],
            'default_seo_title' => fake()->sentence(4),
            'default_seo_description' => fake()->paragraph(),
            'imprint_data' => [],
            'privacy_data' => [],
        ]);
    }

    public function membersOnly(): static
    {
        return $this->state(fn (): array => [
            'visibility' => TenantVisibility::Members,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'visibility' => TenantVisibility::Archived,
        ]);
    }
}
