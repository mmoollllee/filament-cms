<?php

namespace Mmoollllee\Cms\Filament\Pages\Tenancy;

use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\Tenant\HasSpamQuestions;
use Mmoollllee\Cms\Enums\SocialNetwork;
use Mmoollllee\Cms\Enums\TenantUserRole;
use Mmoollllee\Cms\Filament\Forms\MediaField;
use Mmoollllee\Cms\Support\Media\MediaFolders;

/**
 * Shared tenant profile page (branding, contact, SEO/social). The concrete
 * tenant model is resolved via {@see Cms::tenantModel()}; the page assumes that
 * model exposes the branding statics normalizeSocialLinks(), defaultBrandingTenant()
 * and the DEFAULT_PRIMARY_COLOR constant (documented engine assumptions).
 *
 * Apps extend this and customize via the hook methods: {@see tenantFields()},
 * {@see contactExtraSections()} and {@see mutateExtraProfileData()}.
 */
class EditTenantProfilePage extends EditTenantProfile
{
    /**
     * Tenant columns this page persists. Subclasses extend via {@see tenantFields()}.
     *
     * @return list<string>
     */
    protected function tenantFields(): array
    {
        return [
            'name',
            'brand_name',
            'brand_claim',
            'logo_path',
            'secondary_logo_path',
            'mail_logo_path',
            'primary_color',
            'company_name',
            'legal_name',
            'contact_email',
            'contact_phone',
            'street',
            'postal_code',
            'city',
            'country',
            'default_seo_title',
            'default_seo_description',
            'default_og_image_path',
            'footer_text',
            'social_links',
            ...($this->usesSpamQuestions() ? ['spam_questions'] : []),
        ];
    }

    /**
     * Whether the tenant model opts into tenant-configurable spam questions
     * (the {@see HasSpamQuestions} trait). When true, this page renders + persists
     * the "Spam-Schutz" section automatically — no app subclass needed.
     */
    protected function usesSpamQuestions(): bool
    {
        return in_array(HasSpamQuestions::class, class_uses_recursive(Cms::tenantModel()), true);
    }

    public static function getLabel(): string
    {
        return 'Seiten-Einstellungen';
    }

    public function mount(): void
    {
        $this->tenant = Filament::getTenant();
        $tenantClass = Cms::tenantModel();

        abort_unless($this->tenant instanceof $tenantClass, 404);
        abort_if(! static::canView($this->tenant), 403);

        $this->fillForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Site Settings')
                    ->persistTabInQueryString('settings')
                    ->tabs([
                        Tab::make('Marke')
                            ->schema([
                                Section::make('Markenauftritt')
                                    ->description('Pflege Tenant-Name, Claim und zentrale Brand-Assets. Leere Felder erben die Werte des Main Tenants.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Tenant Name')
                                            ->required()
                                            ->live(onBlur: true)
                                            ->maxLength(255),
                                        TextInput::make('brand_name')
                                            ->label('Brand Name')
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('brand_name'))
                                            ->maxLength(255)
                                            ->helperText(fn (Get $get): HtmlString => $this->textFieldHelperText(
                                                field: 'brand_name',
                                                configuredValue: $get('brand_name'),
                                                description: 'Optionaler öffentlicher Name, wenn der Tenant-Name intern anders lauten soll.',
                                            )),
                                        TextInput::make('brand_claim')
                                            ->label('Claim')
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('brand_claim'))
                                            ->helperText(fn (Get $get): HtmlString => $this->textFieldHelperText(
                                                field: 'brand_claim',
                                                configuredValue: $get('brand_claim'),
                                            ))
                                            ->columnSpanFull(),
                                        MediaField::image(
                                            'logo_path',
                                            legacyDirectory: fn (): string => $this->tenantUploadDirectory('branding'),
                                            folderKey: MediaFolders::BRANDING,
                                            imageEditor: true,
                                        )
                                            ->label('Main Logo')
                                            ->helperText(fn (Get $get): HtmlString => $this->assetFieldHelperText(
                                                field: 'logo_path',
                                                configuredValue: $get('logo_path'),
                                            )),
                                        MediaField::image(
                                            'secondary_logo_path',
                                            legacyDirectory: fn (): string => $this->tenantUploadDirectory('branding'),
                                            folderKey: MediaFolders::BRANDING,
                                            imageEditor: true,
                                        )
                                            ->label('Secondary Logo')
                                            ->helperText(fn (Get $get): HtmlString => $this->assetFieldHelperText(
                                                field: 'secondary_logo_path',
                                                configuredValue: $get('secondary_logo_path'),
                                            )),
                                        MediaField::image(
                                            'mail_logo_path',
                                            legacyDirectory: fn (): string => $this->tenantUploadDirectory('branding'),
                                            folderKey: MediaFolders::BRANDING,
                                            imageEditor: true,
                                        )
                                            ->label('E-Mail-Logo')
                                            // Raster only: SVG doesn't render in Gmail/Outlook. Optional — when
                                            // empty, e-mails use the inherited mail logo, else the Main Logo if
                                            // it is a raster image, otherwise the brand name is shown as text.
                                            ->acceptedFileTypes(MediaField::RASTER_IMAGE_TYPES)
                                            ->helperText(fn (Get $get): HtmlString => $this->assetFieldHelperText(
                                                field: 'mail_logo_path',
                                                configuredValue: $get('mail_logo_path')
                                            )),
                                        MediaField::image(
                                            'favicon_path',
                                            legacyDirectory: fn (): string => $this->tenantUploadDirectory('branding'),
                                            folderKey: MediaFolders::BRANDING,
                                        )
                                            ->label('Favicon')
                                            ->acceptedFileTypes(MediaField::FAVICON_TYPES)
                                            ->helperText(fn (Get $get): HtmlString => $this->assetFieldHelperText(
                                                field: 'favicon_path',
                                                configuredValue: $get('favicon_path'),
                                                defaultText: 'Quadratisch, mind. 96×96 px. SVG, PNG oder ICO.',
                                            )),
                                        ColorPicker::make('primary_color')
                                            ->label('Primary Color')
                                            ->hex()
                                            ->placeholder(fn (): string => $this->resolvedPrimaryColorPlaceholder())
                                            ->helperText(fn (Get $get): HtmlString => $this->primaryColorHelperText(
                                                configuredValue: $get('primary_color'),
                                            )),
                                    ]),
                            ]),
                        Tab::make('Kontakt')
                            ->schema([
                                Section::make('Unternehmen')
                                    ->description('Rechtlicher Firmenname und öffentlich sichtbare Kontaktdaten. Leere Felder erben die Werte des Main Tenants.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('company_name')
                                            ->label('Firmenname')
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('company_name', $this->currentTenant()?->displayName())),
                                        TextInput::make('legal_name')
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('legal_name')),
                                        TextInput::make('contact_email')
                                            ->label('Email-Adresse')
                                            ->email()
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('contact_email')),
                                        TextInput::make('contact_phone')
                                            ->label('Telefonnummer')
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('contact_phone')),
                                    ]),
                                Section::make('Adresse')
                                    ->description('Leere Felder erben die Werte des Main Tenants.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('street')
                                            ->label('Straße & Nr.')
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('street'))
                                            ->columnSpanFull(),
                                        TextInput::make('postal_code')
                                            ->label('PLZ')
                                            ->maxLength(32)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('postal_code')),
                                        TextInput::make('city')
                                            ->label('Stadt')
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('city')),
                                        TextInput::make('country')
                                            ->label('Land')
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('country')),
                                    ]),
                                ...$this->spamQuestionsSections(),
                                ...$this->contactExtraSections(),
                            ]),
                        Tab::make('SEO & Social')
                            ->schema([
                                Section::make('SEO Defaults')
                                    ->description('Fallback-Werte für Titel, Beschreibung und Social Sharing. Leere Felder erben die Werte des Main Tenants.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('default_seo_title')
                                            ->maxLength(255)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('default_seo_title', $this->currentTenant()?->resolvedDefaultSeoTitle())),
                                        MediaField::image(
                                            'default_og_image_path',
                                            legacyDirectory: fn (): string => $this->tenantUploadDirectory('seo'),
                                            folderKey: MediaFolders::BRANDING,
                                            imagePreviewHeight: '100',
                                            imageEditor: true,
                                        )
                                            ->label('Default OG Image')
                                            ->helperText(fn (Get $get): HtmlString => $this->assetFieldHelperText(
                                                field: 'default_og_image_path',
                                                configuredValue: $get('default_og_image_path'),
                                            )),
                                        Textarea::make('default_seo_description')
                                            ->rows(4)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('default_seo_description'))
                                            ->helperText(fn (Get $get): HtmlString => $this->textFieldHelperText(
                                                field: 'default_seo_description',
                                                configuredValue: $get('default_seo_description'),
                                            ))
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Social Links')
                                    ->description('Leere Felder erben die Social Links des Main Tenants.')
                                    ->schema([
                                        Repeater::make('social_links')
                                            ->label(' ')
                                            ->addActionLabel('Social Link hinzufügen')
                                            ->defaultItems(0)
                                            ->reorderable(false)
                                            ->columns(2)
                                            ->schema([
                                                Select::make('network')
                                                    ->label('Netzwerk')
                                                    ->options(SocialNetwork::options())
                                                    ->required(),
                                                TextInput::make('url')
                                                    ->label('URL')
                                                    ->url()
                                                    ->required()
                                                    ->maxLength(255),
                                            ])
                                            ->helperText(fn (Get $get): HtmlString => $this->socialLinksHelperText($get('social_links')))
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Footer')
                                    ->schema([
                                        Textarea::make('footer_text')
                                            ->rows(4)
                                            ->placeholder(fn (): string => $this->resolvedTextPlaceholder('footer_text'))
                                            ->helperText(fn (Get $get): HtmlString => $this->textFieldHelperText(
                                                field: 'footer_text',
                                                configuredValue: $get('footer_text'),
                                            )),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The spam-protection section — rendered only when the tenant model uses
     * {@see HasSpamQuestions}. Editing here feeds the public-form {@see \Mmoollllee\Cms\
     * Support\Livewire\Concerns\WithSpamQuiz} rotating quiz.
     *
     * @return array<int, Component>
     */
    protected function spamQuestionsSections(): array
    {
        if (! $this->usesSpamQuestions()) {
            return [];
        }

        return [
            Section::make('Spam-Schutz')
                ->description('Sicherheitsfragen für die Formulare. Bei jedem Aufruf wird zufällig eine gewählt. Leer = Standardfragen; Sub-Tenants erben diese, sofern nicht überschrieben.')
                ->schema([
                    Repeater::make('spam_questions')
                        ->hiddenLabel()
                        ->addActionLabel('Sicherheitsfrage hinzufügen')
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->columns(2)
                        ->schema([
                            TextInput::make('question')
                                ->label('Frage')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('answer')
                                ->label('Antwort(en)')
                                ->helperText('Mehrere akzeptierte Antworten mit Komma trennen, z. B. „7, sieben".')
                                ->required()
                                ->maxLength(255),
                        ])
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * Extra sections appended to the "Kontakt" tab. Override in an app subclass to add
     * project-specific sections (the built-in spam section is added separately).
     *
     * @return array<int, Component>
     */
    protected function contactExtraSections(): array
    {
        return [];
    }

    public static function canView(Model $tenant): bool
    {
        $user = auth()->user();
        $tenantClass = Cms::tenantModel();
        $userClass = Cms::userModel();

        if (! $tenant instanceof $tenantClass || ! $user instanceof $userClass) {
            return false;
        }

        return $user->isSuperadmin() || $user->tenantRole($tenant) === TenantUserRole::Admin;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $tenantClass = Cms::tenantModel();
        $data['social_links'] = $tenantClass::normalizeSocialLinks($data['social_links'] ?? []);

        if (($this->tenant instanceof $tenantClass) && $this->tenant->isBrandingSource() && blank($data['primary_color'] ?? null)) {
            $data['primary_color'] = constant($tenantClass.'::DEFAULT_PRIMARY_COLOR');
        }

        return $this->mutateExtraProfileData($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $tenantClass = Cms::tenantModel();
        $data['social_links'] = $tenantClass::normalizeSocialLinks($data['social_links'] ?? []);

        return $this->mutateExtraProfileData($data);
    }

    /**
     * Hook for app-specific profile fields. Called by both the fill and save mutators.
     * Normalizes spam_questions automatically when the tenant model uses
     * {@see HasSpamQuestions}; override (calling parent) for further app fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateExtraProfileData(array $data): array
    {
        if ($this->usesSpamQuestions() && array_key_exists('spam_questions', $data)) {
            $tenantClass = Cms::tenantModel();
            $data['spam_questions'] = $tenantClass::normalizeSpamQuestions($data['spam_questions'] ?? []);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $tenantClass = Cms::tenantModel();

        if (! $record instanceof $tenantClass) {
            return $record;
        }

        $record->update(array_intersect_key($data, array_flip($this->tenantFields())));

        return $record->refresh();
    }

    protected function tenantUploadDirectory(string $segment): string
    {
        $tenant = $this->tenant;
        $tenantClass = Cms::tenantModel();

        abort_unless($tenant instanceof $tenantClass, 404);

        return "tenants/{$tenant->site_key}/{$segment}";
    }

    protected function resolvedPrimaryColorPlaceholder(): string
    {
        $tenantClass = Cms::tenantModel();

        return $this->currentTenant()?->resolvedPrimaryColor() ?? constant($tenantClass.'::DEFAULT_PRIMARY_COLOR');
    }

    protected function assetFieldHelperText(string $field, mixed $configuredValue, ?string $defaultText = null): HtmlString
    {
        return new HtmlString(view('cms::tenancy.branding-default-helper', [
            'description' => $defaultText,
            'defaultDomain' => $this->defaultBrandingDomain(),
            'showDefaultPreview' => blank($configuredValue),
            'previewUrl' => $this->defaultAssetUrl($field),
            'previewType' => 'asset',
        ])->render());
    }

    protected function primaryColorHelperText(mixed $configuredValue, ?string $defaultText = null): HtmlString
    {
        return new HtmlString(view('cms::tenancy.branding-default-helper', [
            'description' => $defaultText,
            'defaultDomain' => $this->defaultBrandingDomain(),
            'showDefaultPreview' => blank($configuredValue),
            'previewColor' => $this->resolvedPrimaryColorPlaceholder(),
            'previewType' => 'color',
        ])->render());
    }

    protected function textFieldHelperText(string $field, mixed $configuredValue, ?string $description = null, ?string $fallback = null): HtmlString
    {
        return new HtmlString(view('cms::tenancy.branding-default-helper', [
            'description' => $description,
            'defaultDomain' => $this->defaultBrandingDomain(),
            'showDefaultPreview' => blank($configuredValue),
            'previewType' => 'text',
            'previewText' => $this->defaultTextValue($field, $fallback),
        ])->render());
    }

    protected function socialLinksHelperText(mixed $configuredValue): HtmlString
    {
        $previewText = collect($this->currentTenant()?->resolvedSocialLinksForDisplay() ?? [])
            ->map(fn (array $link): string => "{$link['label']}: {$link['url']}")
            ->implode("\n");

        return new HtmlString(view('cms::tenancy.branding-default-helper', [
            'defaultDomain' => $this->defaultBrandingDomain(),
            'showDefaultPreview' => blank($configuredValue),
            'previewType' => 'text',
            'previewText' => filled($previewText) ? $previewText : null,
            'emptyMessage' => 'Aktuell sind keine Default-Social-Links hinterlegt.',
        ])->render());
    }

    protected function defaultBrandingDomain(): string
    {
        $tenantClass = Cms::tenantModel();

        return $tenantClass::defaultBrandingTenant()?->primary_domain ?? '–';
    }

    protected function defaultAssetUrl(string $field): ?string
    {
        $tenantClass = Cms::tenantModel();
        $brandingTenant = $tenantClass::defaultBrandingTenant();

        if (! $brandingTenant instanceof $tenantClass) {
            return null;
        }

        return match ($field) {
            'logo_path' => $brandingTenant->resolvedMainLogoUrl(),
            'secondary_logo_path' => $brandingTenant->resolvedSecondaryLogoUrl(),
            // The effective mail logo the tenant inherits (dedicated mail logo, else the
            // raster main-logo fallback) — what its e-mails will actually show.
            'mail_logo_path' => $brandingTenant->resolvedMailLogoUrl(),
            'favicon_path' => $brandingTenant->resolvedFaviconUrl(),
            'default_og_image_path' => $brandingTenant->resolvedDefaultOgImageUrl(),
            default => null,
        };
    }

    protected function currentTenant(): ?Model
    {
        $tenantClass = Cms::tenantModel();

        return $this->tenant instanceof $tenantClass ? $this->tenant : null;
    }

    protected function resolvedTextPlaceholder(string $field, ?string $fallback = null): string
    {
        return $this->resolvedTextValue($field, $fallback) ?? '';
    }

    protected function defaultTextValue(string $field, ?string $fallback = null): ?string
    {
        $tenant = $this->currentTenant();
        $tenantClass = Cms::tenantModel();
        $brandingTenant = $tenantClass::defaultBrandingTenant();

        if (! $tenant instanceof $tenantClass || ! $brandingTenant instanceof $tenantClass || $brandingTenant->is($tenant)) {
            return null;
        }

        return match ($field) {
            'brand_name' => $brandingTenant->displayName(),
            'brand_claim' => $brandingTenant->resolvedBrandClaim(),
            'default_seo_title' => $brandingTenant->resolvedDefaultSeoTitle(),
            'default_seo_description' => $brandingTenant->resolvedDefaultSeoDescription(),
            'company_name' => (string) $brandingTenant->resolvedSiteSetting('company_name', $brandingTenant->displayName()),
            default => $this->normalizedTextValue($brandingTenant->resolvedSiteSetting($field, $fallback)),
        };
    }

    protected function resolvedTextValue(string $field, ?string $fallback = null): ?string
    {
        $tenant = $this->currentTenant();

        if ($tenant === null) {
            return null;
        }

        return match ($field) {
            'brand_name' => $tenant->displayName(),
            'brand_claim' => $tenant->resolvedBrandClaim(),
            'default_seo_title' => $tenant->resolvedDefaultSeoTitle(),
            'default_seo_description' => $tenant->resolvedDefaultSeoDescription(),
            'company_name' => (string) $tenant->resolvedSiteSetting('company_name', $tenant->displayName()),
            default => $this->normalizedTextValue($tenant->resolvedSiteSetting($field, $fallback)),
        };
    }

    protected function normalizedTextValue(mixed $value): ?string
    {
        if (is_array($value)) {
            return $value === [] ? null : json_encode($value);
        }

        $resolvedValue = trim((string) $value);

        return filled($resolvedValue) ? $resolvedValue : null;
    }
}
