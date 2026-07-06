<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Mmoollllee\Cms\Concerns\Tenant\HasContents;
use Mmoollllee\Cms\Concerns\Tenant\HasSpamQuestions;
use Mmoollllee\Cms\Concerns\Tenant\HasTenantUsers;
use Mmoollllee\Cms\Concerns\Tenant\InheritsBranding;
use Mmoollllee\Cms\Enums\TenantVisibility;

class Tenant extends Model implements \Mmoollllee\Cms\Contracts\Tenant
{
    use HasContents;              // contents() + visibleContents()
    use HasSpamQuestions;         // tenant-configured spam-protection questions
    use HasTenantUsers;           // users()/creator(), hasUser(), isVisibleTo()
    use InheritsBranding;         // resolved*() branding cascade + SEO defaults

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'site_key',
        'primary_domain',
        'visibility',
        'brand_name',
        'brand_claim',
        'logo_path',
        'secondary_logo_path',
        'primary_color',
        'default_locale',
        'timezone',
        'created_by',
        'company_name',
        'legal_name',
        'contact_email',
        'contact_phone',
        'contact_fax',
        'street',
        'postal_code',
        'city',
        'country',
        'footer_text',
        'social_links',
        'default_seo_title',
        'default_seo_description',
        'default_og_image_path',
        'imprint_data',
        'privacy_data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => TenantVisibility::class,
            'social_links' => 'array',
            'imprint_data' => 'array',
            'privacy_data' => 'array',
        ];
    }
}
