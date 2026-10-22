<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CareerPortalAsset;
use App\Models\CareerPortalConfiguration;
use App\Models\CareerPortalConfigurationVersion;
use App\Models\CareerPortalPublicationEvent;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CareerPortalConfigurationService
{
    public const SCHEMA_VERSION = 1;

    public const CACHE_KEY_GLOBAL = 'career_portal:published:global:v1';

    public const CACHE_KEY_TENANT_PREFIX = 'career_portal:published:tenant:v1:';

    public const PERMITTED_SECTIONS = [
        'hero',
        'value_propositions',
        'explore_opportunities',
        'job_categories',
        'featured_jobs',
        'life_at_tringers',
        'employee_stories',
        'final_cta',
    ];

    public const APPROVED_FONTS = [
        'Inter',
        'Plus Jakarta Sans',
        'Public Sans',
        'System UI',
    ];

    public const APPROVED_ICONS = [
        'target',
        'trending-up',
        'users',
        'globe',
        'code',
        'layers',
        'bar-chart',
        'briefcase',
        'megaphone',
        'building',
        'shield',
        'sparkles',
        'heart',
        'award',
    ];

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * Authoritative default configuration reproducing the approved Tringers Career Portal specification (§4 & §5).
     *
     * @return array<string, mixed>
     */
    public function getTringersDefaultPayload(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'overview' => [
                'portal_enabled' => true,
                'section_order' => [
                    'hero',
                    'value_propositions',
                    'explore_opportunities',
                    'featured_jobs',
                    'life_at_tringers',
                    'employee_stories',
                    'final_cta',
                ],
                'section_visibility' => [
                    'header' => true,
                    'hero' => true,
                    'value_propositions' => true,
                    'explore_opportunities' => true,
                    'featured_jobs' => true,
                    'life_at_tringers' => true,
                    'employee_stories' => true,
                    'final_cta' => true,
                    'footer' => true,
                ],
                'tenant_overridable_keys' => [
                    'hero_search.eyebrow',
                    'hero_search.headline',
                    'hero_search.supporting_text',
                    'life_at_tringers.heading',
                    'life_at_tringers.body',
                    'seo.default_title',
                    'seo.default_description',
                    'brand_theme.primary_color',
                ],
            ],
            'brand_theme' => [
                'brand_name' => 'Tringers',
                'logo_text' => 'Tringers',
                'logo_subtext' => 'Careers',
                'logo_url' => '',
                'favicon_url' => '/favicon.ico',
                'primary_color' => '#0B5ED7',
                'secondary_color' => '#0A2540',
                'heading_color' => '#0F172A',
                'body_color' => '#334155',
                'muted_color' => '#475569',
                'surface_color' => '#FFFFFF',
                'alt_surface_color' => '#F4F8FD',
                'border_color' => '#E2E8F0',
                'success_color' => '#15803D',
                'warning_color' => '#B45309',
                'error_color' => '#B91C1C',
                'font_family' => 'Inter',
                'base_font_size_px' => 16,
                'heading_weight' => 700,
                'content_max_width_px' => 1240,
                'section_spacing_px' => 72,
                'card_radius_px' => 12,
                'card_shadow_preset' => 'subtle',
                'button_radius_px' => 8,
            ],
            'header_navigation' => [
                'sticky_header' => true,
                'header_height_px' => 72,
                'nav_items' => [
                    ['id' => 'nav-careers', 'label' => 'Careers', 'url' => '#featured-jobs', 'target' => '_self', 'visible' => true, 'order' => 1],
                    ['id' => 'nav-about', 'label' => 'About Us', 'url' => '#life-at-tringers', 'target' => '_self', 'visible' => true, 'order' => 2],
                    ['id' => 'nav-life', 'label' => 'Life at Tringers', 'url' => '#life-at-tringers', 'target' => '_self', 'visible' => true, 'order' => 3],
                    ['id' => 'nav-locations', 'label' => 'Our Locations', 'url' => '#explore-opportunities', 'target' => '_self', 'visible' => true, 'order' => 4],
                    ['id' => 'nav-students', 'label' => 'Students', 'url' => '/?q=Intern', 'target' => '_self', 'visible' => true, 'order' => 5],
                    ['id' => 'nav-diversity', 'label' => 'Diversity & Inclusion', 'url' => '#value-propositions', 'target' => '_self', 'visible' => true, 'order' => 6],
                ],
                'cta_label' => 'Join Our Talent Network',
                'cta_url' => '#featured-jobs',
                'cta_visible' => true,
            ],
            'hero_search' => [
                'eyebrow' => 'BUILD A BRIGHTER TOMORROW',
                'headline' => 'Grow Your Career With Tringers',
                'supporting_text' => 'Be part of a global team solving real-world problems with technology, innovation and purpose.',
                'search_placeholder' => 'Search jobs by keyword, role or location',
                'search_button_label' => 'Search Jobs',
                'show_category_filter' => true,
                'show_location_filter' => true,
                'show_work_mode_filter' => true,
                'desktop_image_url' => '/images/career-portal/hero-workplace.jpg',
                'mobile_image_url' => '/images/career-portal/hero-workplace.jpg',
                'image_alt' => 'Tringers engineering and product professionals collaborating in a modern workspace',
                'focal_point_x' => 72,
                'focal_point_y' => 38,
                'overlay_opacity' => 0.62,
                'min_height_px' => 500,
                'text_alignment' => 'left',
                'fallback_bg_color' => '#0A2540',
                'popular_searches_label' => 'Popular:',
                'popular_searches_mode' => 'execute',
                'popular_searches' => [
                    ['id' => 'pop-software-engineer', 'label' => 'Software Engineer', 'query' => 'Software Engineer', 'type' => 'q', 'visible' => true, 'order' => 1],
                    ['id' => 'pop-product-manager', 'label' => 'Product Manager', 'query' => 'Product Manager', 'type' => 'q', 'visible' => true, 'order' => 2],
                    ['id' => 'pop-data-analyst', 'label' => 'Data Analyst', 'query' => 'Data Analyst', 'type' => 'q', 'visible' => true, 'order' => 3],
                    ['id' => 'pop-remote', 'label' => 'Remote', 'query' => 'REMOTE', 'type' => 'work_mode', 'visible' => true, 'order' => 4],
                    ['id' => 'pop-india', 'label' => 'India', 'query' => 'India', 'type' => 'location', 'visible' => true, 'order' => 5],
                ],
            ],
            'value_propositions' => [
                'section_heading' => 'Why Build Your Career With Tringers',
                'show_section_heading' => false,
                'desktop_columns' => 4,
                'items' => [
                    [
                        'id' => 'vp-meaningful-work',
                        'title' => 'Meaningful Work',
                        'description' => 'Solve real business challenges that make an impact.',
                        'icon' => 'target',
                        'accent' => 'blue',
                        'visible' => true,
                        'order' => 1,
                    ],
                    [
                        'id' => 'vp-continuous-growth',
                        'title' => 'Continuous Growth',
                        'description' => 'Learn, upskill and advance your career.',
                        'icon' => 'trending-up',
                        'accent' => 'indigo',
                        'visible' => true,
                        'order' => 2,
                    ],
                    [
                        'id' => 'vp-inclusive-culture',
                        'title' => 'Inclusive Culture',
                        'description' => 'Be yourself and belong.',
                        'icon' => 'users',
                        'accent' => 'teal',
                        'visible' => true,
                        'order' => 3,
                    ],
                    [
                        'id' => 'vp-global-opportunities',
                        'title' => 'Global Opportunities',
                        'description' => 'Work with teams across geographies.',
                        'icon' => 'globe',
                        'accent' => 'sky',
                        'visible' => true,
                        'order' => 4,
                    ],
                ],
            ],
            'job_categories' => [
                'heading' => 'Explore Opportunities',
                'view_all_label' => 'View All Job Categories',
                'view_all_url' => '#featured-jobs',
                'empty_category_policy' => 'show_zero',
                'count_scope_mode' => 'portal_scope',
                'categories' => [
                    [
                        'id' => 'cat-engineering',
                        'slug' => 'engineering',
                        'name' => 'Engineering',
                        'icon' => 'code',
                        'accent' => 'blue',
                        'keywords' => ['Engineer', 'Architect', 'Platform', 'Developer', 'Cloud', 'AI', 'Systems', 'Software'],
                        'department_codes' => ['ENG-AI', 'CLIN-INFO'],
                        'visible' => true,
                        'order' => 1,
                    ],
                    [
                        'id' => 'cat-product-design',
                        'slug' => 'product-design',
                        'name' => 'Product & Design',
                        'icon' => 'layers',
                        'accent' => 'purple',
                        'keywords' => ['Product', 'Design', 'UX', 'UI'],
                        'department_codes' => ['PROD-DES'],
                        'visible' => true,
                        'order' => 2,
                    ],
                    [
                        'id' => 'cat-data-analytics',
                        'slug' => 'data-analytics',
                        'name' => 'Data & Analytics',
                        'icon' => 'bar-chart',
                        'accent' => 'teal',
                        'keywords' => ['Data', 'Analytics', 'Machine Learning', 'BI', 'Informatics'],
                        'department_codes' => ['DATA-AN'],
                        'visible' => true,
                        'order' => 3,
                    ],
                    [
                        'id' => 'cat-consulting',
                        'slug' => 'consulting',
                        'name' => 'Consulting',
                        'icon' => 'briefcase',
                        'accent' => 'amber',
                        'keywords' => ['Consultant', 'Consulting', 'Advisory', 'Strategy'],
                        'department_codes' => ['CONS'],
                        'visible' => true,
                        'order' => 4,
                    ],
                    [
                        'id' => 'cat-sales-marketing',
                        'slug' => 'sales-marketing',
                        'name' => 'Sales & Marketing',
                        'icon' => 'megaphone',
                        'accent' => 'rose',
                        'keywords' => ['Sales', 'Marketing', 'Growth', 'Account'],
                        'department_codes' => ['SALES-MKT'],
                        'visible' => true,
                        'order' => 5,
                    ],
                    [
                        'id' => 'cat-corporate-functions',
                        'slug' => 'corporate-functions',
                        'name' => 'Corporate Functions',
                        'icon' => 'building',
                        'accent' => 'slate',
                        'keywords' => ['HR', 'Finance', 'Legal', 'Operations', 'Compliance'],
                        'department_codes' => ['CORP-OPS'],
                        'visible' => true,
                        'order' => 6,
                    ],
                ],
            ],
            'featured_jobs' => [
                'heading' => 'Featured Jobs',
                'subtitle' => 'Explore Approved Open Positions',
                'view_all_label' => 'View All Jobs',
                'view_all_url' => '#all-open-positions',
                'selection_mode' => 'hybrid',
                'manual_job_ids' => [],
                'max_items' => 5,
                'show_posting_age' => true,
                'show_department_tag' => true,
                'empty_state_message' => 'No published positions match your current filter.',
            ],
            'life_at_tringers' => [
                'eyebrow' => 'LIFE AT TRINGERS',
                'heading' => 'A Place to Belong, Grow and Make an Impact',
                'body' => 'We empower our people with the opportunity to learn, innovate and create meaningful change for our clients, communities and the world.',
                'cta_label' => 'Explore Life at Tringers',
                'cta_url' => '#featured-jobs',
                'image_url' => '/images/career-portal/culture-team.jpg',
                'image_alt' => 'Colleagues collaborating around a wooden table in a sunlit Tringers workspace',
                'focal_point_x' => 50,
                'focal_point_y' => 45,
                'image_position' => 'left',
                'background_style' => 'pale_blue',
                'show_live_verified_counts' => true,
                'metrics' => [
                    [
                        'id' => 'metric- illustrative-team',
                        'value' => '3,000+',
                        'label' => 'Team Members',
                        'verification_note' => 'Illustrative mockup figure — requires HR headcount verification before publishing.',
                        'approved' => false,
                        'published' => false,
                        'order' => 1,
                    ],
                    [
                        'id' => 'metric-illustrative-locations',
                        'value' => '15+',
                        'label' => 'Global Locations',
                        'verification_note' => 'Illustrative mockup figure — requires corporate real estate verification before publishing.',
                        'approved' => false,
                        'published' => false,
                        'order' => 2,
                    ],
                    [
                        'id' => 'metric-illustrative-satisfaction',
                        'value' => '95%',
                        'label' => 'Employee Satisfaction',
                        'verification_note' => 'Illustrative mockup figure — requires annual pulse survey sign-off before publishing.',
                        'approved' => false,
                        'published' => false,
                        'order' => 3,
                    ],
                ],
            ],
            'employee_stories' => [
                'heading' => 'Our People, Their Stories',
                'view_more_label' => 'View More Stories',
                'view_more_url' => '#life-at-tringers',
                'empty_behavior' => 'fallback_banner',
                'fallback_heading' => 'Authentic Voices Across Engineering, Product & Consulting',
                'fallback_body' => 'Verified employee stories are published here once approved with explicit employee consent in Career Portal Configuration.',
                'max_cards' => 3,
                'quote_max_length' => 280,
                'stories' => [],
            ],
            'final_cta' => [
                'eyebrow' => 'YOUR NEXT OPPORTUNITY AWAITS',
                'heading' => 'Let’s Build What’s Next',
                'body' => 'Join a team of problem solvers, creators and change makers. Explore exciting career opportunities at Tringers.',
                'cta_label' => 'View All Jobs',
                'cta_url' => '#featured-jobs',
                'image_url' => '/images/career-portal/cta-mountain.jpg',
                'image_alt' => 'Teammates helping each other ascend a scenic mountain trail at sunrise',
                'focal_point_x' => 55,
                'focal_point_y' => 45,
                'overlay_opacity' => 0.60,
                'min_height_px' => 360,
                'text_alignment' => 'left',
            ],
            'footer' => [
                'brand_name' => 'Tringers',
                'brand_statement' => 'People. Ideas. Impact.',
                'copyright_holder' => 'Tringers',
                'quick_links_heading' => 'Quick Links',
                'quick_links' => [
                    ['id' => 'ql-home', 'label' => 'Careers Home', 'url' => '/', 'visible' => true, 'order' => 1],
                    ['id' => 'ql-categories', 'label' => 'Job Categories', 'url' => '#explore-opportunities', 'visible' => true, 'order' => 2],
                    ['id' => 'ql-life', 'label' => 'Life at Tringers', 'url' => '#life-at-tringers', 'visible' => true, 'order' => 3],
                    ['id' => 'ql-locations', 'label' => 'Our Locations', 'url' => '#featured-jobs', 'visible' => true, 'order' => 4],
                ],
                'useful_links_heading' => 'Useful Links',
                'useful_links' => [
                    ['id' => 'ul-about', 'label' => 'About Us', 'url' => '#life-at-tringers', 'visible' => true, 'order' => 1],
                    ['id' => 'ul-diversity', 'label' => 'Diversity & Inclusion', 'url' => '#value-propositions', 'visible' => true, 'order' => 2],
                    ['id' => 'ul-students', 'label' => 'Students & Graduates', 'url' => '/?q=Intern', 'visible' => true, 'order' => 3],
                    ['id' => 'ul-talent', 'label' => 'Join Our Talent Network', 'url' => '#featured-jobs', 'visible' => true, 'order' => 4],
                ],
                'social_heading' => 'Connect With Us',
                'social_links' => [
                    ['id' => 'soc-linkedin', 'platform' => 'linkedin', 'label' => 'LinkedIn', 'url' => '', 'approved' => false, 'visible' => false],
                    ['id' => 'soc-x', 'platform' => 'x', 'label' => 'X', 'url' => '', 'approved' => false, 'visible' => false],
                    ['id' => 'soc-youtube', 'platform' => 'youtube', 'label' => 'YouTube', 'url' => '', 'approved' => false, 'visible' => false],
                    ['id' => 'soc-instagram', 'platform' => 'instagram', 'label' => 'Instagram', 'url' => '', 'approved' => false, 'visible' => false],
                    ['id' => 'soc-facebook', 'platform' => 'facebook', 'label' => 'Facebook', 'url' => '', 'approved' => false, 'visible' => false],
                ],
                'legal_links' => [
                    ['id' => 'leg-privacy', 'label' => 'Privacy Policy', 'url' => '/legal/privacy-policy', 'visible' => true],
                    ['id' => 'leg-terms', 'label' => 'Terms of Use', 'url' => '/legal/terms-of-use', 'visible' => true],
                    ['id' => 'leg-cookies', 'label' => 'Cookie Policy', 'url' => '/legal/cookie-policy', 'visible' => true],
                ],
            ],
            'seo' => [
                'default_title' => 'Tringers Careers — Grow Your Career With Tringers',
                'default_description' => 'Explore approved open positions across engineering, product, data, consulting and corporate functions at Tringers. People. Ideas. Impact.',
                'canonical_base_url' => 'https://career.tringers.com',
                'og_title' => 'Grow Your Career With Tringers',
                'og_description' => 'Be part of a global team solving real-world problems with technology, innovation and purpose.',
                'og_image_url' => '/images/career-portal/hero-workplace.jpg',
                'twitter_card' => 'summary_large_image',
                'robots_policy' => 'index,follow',
                'enable_organization_jsonld' => true,
                'enable_jobposting_jsonld' => true,
                'default_locale' => 'en',
            ],
            'accessibility' => [
                'wcag_target' => 'AA',
                'min_contrast_ratio' => 4.5,
                'respect_reduced_motion' => true,
                'require_image_alt_text' => true,
                'visible_focus_outline' => true,
                'mobile_breakpoint_px' => 768,
                'desktop_breakpoint_px' => 1024,
            ],
            'analytics_consent' => [
                'analytics_enabled' => false,
                'require_cookie_consent' => true,
                'consent_banner_enabled' => true,
                'consent_banner_text' => 'We use strictly necessary cookies to operate the career portal and optional privacy-preserving analytics cookies only with your consent.',
                'tracked_events' => [
                    'portal_viewed',
                    'search_submitted',
                    'popular_search_selected',
                    'category_selected',
                    'job_detail_viewed',
                    'apply_cta_selected',
                    'talent_network_cta_selected',
                ],
                'redact_search_free_text' => true,
            ],
        ];
    }

    public function ensureGlobalConfiguration(?User $actor = null): CareerPortalConfiguration
    {
        if (! Schema::hasTable('career_portal_configurations')) {
            $default = $this->getTringersDefaultPayload();

            return new CareerPortalConfiguration([
                'scope' => CareerPortalConfiguration::SCOPE_GLOBAL,
                'status' => CareerPortalConfiguration::STATUS_PUBLISHED,
                'schema_version' => self::SCHEMA_VERSION,
                'draft_version' => 1,
                'draft_payload' => $default,
                'published_payload' => $default,
                'published_version_number' => 1,
                'validation_status' => 'VALID',
            ]);
        }

        $existing = CareerPortalConfiguration::query()
            ->where('scope', CareerPortalConfiguration::SCOPE_GLOBAL)
            ->whereNull('tenant_id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($actor) {
            $locked = CareerPortalConfiguration::query()
                ->where('scope', CareerPortalConfiguration::SCOPE_GLOBAL)
                ->whereNull('tenant_id')
                ->lockForUpdate()
                ->first();

            if ($locked) {
                return $locked;
            }

            $default = $this->getTringersDefaultPayload();
            $config = CareerPortalConfiguration::create([
                'scope' => CareerPortalConfiguration::SCOPE_GLOBAL,
                'tenant_id' => null,
                'status' => CareerPortalConfiguration::STATUS_PUBLISHED,
                'schema_version' => self::SCHEMA_VERSION,
                'draft_version' => 1,
                'draft_payload' => $default,
                'validation_status' => 'VALID',
                'validation_errors' => [],
                'last_validated_at' => now(),
                'published_payload' => $default,
                'published_version_number' => 1,
                'published_at' => now(),
                'published_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
            ]);

            $version = CareerPortalConfigurationVersion::create([
                'configuration_id' => $config->id,
                'tenant_id' => null,
                'version_number' => 1,
                'status' => 'PUBLISHED',
                'schema_version' => self::SCHEMA_VERSION,
                'snapshot' => $default,
                'validation_summary' => ['valid' => true, 'errors' => []],
                'change_summary' => 'Initial Tringers default career portal configuration baseline (v1.0).',
                'created_by_user_id' => $actor?->id,
            ]);

            $config->forceFill(['active_published_version_id' => $version->id])->save();

            CareerPortalPublicationEvent::create([
                'configuration_id' => $config->id,
                'version_id' => $version->id,
                'action' => 'INITIAL_BASELINE_PUBLISHED',
                'actor_user_id' => $actor?->id,
                'actor_identifier' => $actor?->email ?? 'system-bootstrap',
                'change_summary' => 'Initialized default Tringers public career portal configuration.',
            ]);

            $this->ensureDefaultAssets($actor);

            return $config;
        });
    }

    public function ensureTenantConfiguration(Tenant $tenant, ?User $actor = null): CareerPortalConfiguration
    {
        $existing = CareerPortalConfiguration::query()
            ->where('scope', CareerPortalConfiguration::SCOPE_TENANT)
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $tenantDefaults = [
            'schema_version' => self::SCHEMA_VERSION,
            'hero_search' => [
                'eyebrow' => strtoupper($tenant->name),
                'headline' => 'Explore Open Positions at '.$tenant->name,
                'supporting_text' => (string) ($tenant->branding_config['tagline'] ?? "Discover approved career opportunities and build the future with {$tenant->name}."),
            ],
            'life_at_tringers' => [
                'heading' => 'Life at '.$tenant->name,
                'body' => 'Collaborate with multidisciplinary teams delivering governed enterprise innovation at '.$tenant->name.'.',
            ],
            'seo' => [
                'default_title' => $tenant->name.' Careers — Open Positions',
                'default_description' => 'Browse verified published roles at '.$tenant->name.'.',
            ],
            'brand_theme' => [
                'primary_color' => (string) ($tenant->branding_config['primary_color'] ?? '#0B5ED7'),
            ],
        ];

        $config = CareerPortalConfiguration::create([
            'scope' => CareerPortalConfiguration::SCOPE_TENANT,
            'tenant_id' => $tenant->id,
            'status' => CareerPortalConfiguration::STATUS_PUBLISHED,
            'schema_version' => self::SCHEMA_VERSION,
            'draft_version' => 1,
            'draft_payload' => $tenantDefaults,
            'validation_status' => 'VALID',
            'validation_errors' => [],
            'last_validated_at' => now(),
            'published_payload' => $tenantDefaults,
            'published_version_number' => 1,
            'published_at' => now(),
            'published_by_user_id' => $actor?->id,
            'updated_by_user_id' => $actor?->id,
        ]);

        $version = CareerPortalConfigurationVersion::create([
            'configuration_id' => $config->id,
            'tenant_id' => $tenant->id,
            'version_number' => 1,
            'status' => 'PUBLISHED',
            'schema_version' => self::SCHEMA_VERSION,
            'snapshot' => $tenantDefaults,
            'validation_summary' => ['valid' => true, 'errors' => []],
            'change_summary' => "Initial tenant career portal configuration for {$tenant->slug}.",
            'created_by_user_id' => $actor?->id,
        ]);

        $config->forceFill(['active_published_version_id' => $version->id])->save();

        return $config;
    }

    public function ensureDefaultAssets(?User $actor = null): void
    {
        if (! Schema::hasTable('career_portal_assets')) {
            return;
        }

        $defaults = [
            [
                'asset_key' => 'hero-workplace',
                'title' => 'Tringers Hero Workplace Photography',
                'alt_text' => 'Tringers engineering and product professionals collaborating in a modern workspace',
                'public_path' => '/images/career-portal/hero-workplace.jpg',
                'mime_type' => 'image/jpeg',
                'width' => 1408,
                'height' => 768,
                'focal_point_x' => 72,
                'focal_point_y' => 38,
            ],
            [
                'asset_key' => 'culture-team',
                'title' => 'Life at Tringers Team Culture Photography',
                'alt_text' => 'Colleagues collaborating around a wooden table in a sunlit Tringers workspace',
                'public_path' => '/images/career-portal/culture-team.jpg',
                'mime_type' => 'image/jpeg',
                'width' => 1280,
                'height' => 960,
                'focal_point_x' => 50,
                'focal_point_y' => 45,
            ],
            [
                'asset_key' => 'cta-mountain',
                'title' => 'Aspirational Mountain Trail Final CTA Banner',
                'alt_text' => 'Teammates helping each other ascend a scenic mountain trail at sunrise',
                'public_path' => '/images/career-portal/cta-mountain.jpg',
                'mime_type' => 'image/jpeg',
                'width' => 1408,
                'height' => 768,
                'focal_point_x' => 55,
                'focal_point_y' => 45,
            ],
        ];

        foreach ($defaults as $asset) {
            CareerPortalAsset::updateOrCreate(
                ['asset_key' => $asset['asset_key'], 'tenant_id' => null],
                [
                    ...$asset,
                    'approval_status' => 'APPROVED',
                    'uploaded_by_user_id' => $actor?->id,
                ]
            );
        }
    }

    /**
     * Resolves the effective public or preview configuration payload, applying the §7.3 Precedence Model
     * and filtering out any unapproved metrics, unapproved testimonials, or unapproved social links.
     *
     * @return array<string, mixed>
     */
    public function getResolvedPublicPayload(
        ?Tenant $tenant = null,
        bool $useDraftPreview = false,
        ?CareerPortalConfiguration $previewConfig = null,
    ): array {
        if ($useDraftPreview && $previewConfig !== null) {
            $globalConfig = $this->ensureGlobalConfiguration();
            $base = $this->mergeWithDefaults(
                $previewConfig->scope === CareerPortalConfiguration::SCOPE_GLOBAL
                    ? (array) $previewConfig->draft_payload
                    : (array) ($globalConfig->published_payload ?? $this->getTringersDefaultPayload())
            );

            if ($tenant !== null) {
                $tenantOverrides = $previewConfig->scope === CareerPortalConfiguration::SCOPE_TENANT
                    ? (array) $previewConfig->draft_payload
                    : (array) ($this->ensureTenantConfiguration($tenant)->published_payload ?? []);
                $base = $this->applyAllowedTenantOverrides($base, $tenantOverrides, $tenant);
            }

            return $this->filterPublicRenderableContent($base, $tenant);
        }

        $cacheKey = $tenant !== null
            ? self::CACHE_KEY_TENANT_PREFIX.$tenant->id
            : self::CACHE_KEY_GLOBAL;

        $cached = Cache::remember($cacheKey, 600, function () use ($tenant) {
            $globalConfig = $this->ensureGlobalConfiguration();
            $publishedGlobal = ($globalConfig->status === CareerPortalConfiguration::STATUS_UNPUBLISHED)
                ? $this->getTringersDefaultPayload()
                : (array) ($globalConfig->published_payload ?? $this->getTringersDefaultPayload());

            $merged = $this->mergeWithDefaults($publishedGlobal);

            if ($tenant !== null) {
                $tenantConfig = $this->ensureTenantConfiguration($tenant);
                $tenantPayload = (array) ($tenantConfig->published_payload ?? []);
                $merged = $this->applyAllowedTenantOverrides($merged, $tenantPayload, $tenant);
            }

            return $merged;
        });

        return $this->filterPublicRenderableContent($cached, $tenant);
    }

    /**
     * Enforces §7.3 Precedence Model: only keys listed in overview.tenant_overridable_keys
     * may be overridden on a tenant career portal. Global root portal is never altered.
     *
     * @param  array<string, mixed>  $globalPayload
     * @param  array<string, mixed>  $tenantPayload
     * @return array<string, mixed>
     */
    public function applyAllowedTenantOverrides(array $globalPayload, array $tenantPayload, Tenant $tenant): array
    {
        $resolved = $globalPayload;
        $allowedKeys = (array) ($globalPayload['overview']['tenant_overridable_keys'] ?? []);

        foreach ($allowedKeys as $dotKey) {
            if (! is_string($dotKey) || $dotKey === '') {
                continue;
            }
            $val = data_get($tenantPayload, $dotKey);
            if ($val !== null && $val !== '') {
                if (str_ends_with($dotKey, '_color')) {
                    $color = $this->sanitizeHexColor((string) $val, '');
                    if ($color !== '' && $this->contrastRatio('#FFFFFF', $color) >= 4.5) {
                        data_set($resolved, $dotKey, $color);
                    }
                } else {
                    data_set($resolved, $dotKey, $this->sanitizePlainText((string) $val, 300));
                }
            }
        }

        // Ensure tenant portal heading clearly identifies the tenant organization
        if (empty(data_get($tenantPayload, 'hero_search.headline'))) {
            data_set($resolved, 'hero_search.headline', 'Explore Open Positions at '.$tenant->name);
        }

        data_set($resolved, 'featured_jobs.subtitle', 'Explore Open Positions at '.$tenant->name);

        return $resolved;
    }

    /**
     * Filters out unapproved metrics, unapproved testimonials, and unapproved social links
     * so unverified claims or synthetic testimonials are never exposed on the public portal (§4.6, §4.7, §4.9, §9).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function filterPublicRenderableContent(array $payload, ?Tenant $tenant = null): array
    {
        // 1. Order and filter navigation items
        $navItems = collect((array) ($payload['header_navigation']['nav_items'] ?? []))
            ->filter(fn ($item) => is_array($item) && ! empty($item['visible']) && ! empty($item['label']) && $this->isValidNavigationUrl((string) ($item['url'] ?? '')))
            ->sortBy(fn ($item) => (int) ($item['order'] ?? 99))
            ->values()
            ->all();
        $payload['header_navigation']['renderable_nav_items'] = $navItems;

        // 2. Order and filter popular searches
        $popSearches = collect((array) ($payload['hero_search']['popular_searches'] ?? []))
            ->filter(fn ($item) => is_array($item) && ! empty($item['visible']) && ! empty($item['label']) && ! empty($item['query']))
            ->sortBy(fn ($item) => (int) ($item['order'] ?? 99))
            ->values()
            ->all();
        $payload['hero_search']['renderable_popular_searches'] = $popSearches;

        // 3. Order and filter value propositions
        $vps = collect((array) ($payload['value_propositions']['items'] ?? []))
            ->filter(fn ($item) => is_array($item) && ! empty($item['visible']) && ! empty($item['title']))
            ->sortBy(fn ($item) => (int) ($item['order'] ?? 99))
            ->values()
            ->all();
        $payload['value_propositions']['renderable_items'] = $vps;

        // 4. Filter culture metrics: ONLY approved AND published metrics with a verification note are rendered
        $approvedMetrics = collect((array) ($payload['life_at_tringers']['metrics'] ?? []))
            ->filter(fn ($m) => is_array($m)
                && ! empty($m['approved'])
                && ! empty($m['published'])
                && trim((string) ($m['value'] ?? '')) !== ''
                && trim((string) ($m['label'] ?? '')) !== ''
                && trim((string) ($m['verification_note'] ?? '')) !== '')
            ->sortBy(fn ($m) => (int) ($m['order'] ?? 99))
            ->map(fn ($m) => [
                'id' => (string) ($m['id'] ?? ''),
                'value' => (string) $m['value'],
                'label' => (string) $m['label'],
            ])
            ->values()
            ->all();

        // If show_live_verified_counts is enabled and no custom approved metrics override it, provide live database-verified counts
        if (empty($approvedMetrics) && ! empty($payload['life_at_tringers']['show_live_verified_counts'])) {
            $eligibleJobsCount = $this->baseEligibleJobsQuery($tenant)->count();
            $activeLocationsCount = Schema::hasTable('locations')
                ? Location::withoutTenantScope()
                    ->when($tenant !== null, fn ($q) => $q->where('tenant_id', $tenant->id))
                    ->where('is_active', true)
                    ->count()
                : 0;
            $activeTenantsCount = Schema::hasTable('tenants')
                ? Tenant::query()->where('status', 'ACTIVE')->count()
                : 1;

            $approvedMetrics = [
                [
                    'id' => 'live-verified-openings',
                    'value' => (string) $eligibleJobsCount,
                    'label' => $eligibleJobsCount === 1 ? 'Published Open Role' : 'Published Open Roles',
                ],
                [
                    'id' => 'live-verified-locations',
                    'value' => (string) max(1, $activeLocationsCount),
                    'label' => $activeLocationsCount === 1 ? 'Active Hiring Hub' : 'Active Hiring Hubs',
                ],
                [
                    'id' => 'live-verified-orgs',
                    'value' => $tenant !== null ? '100%' : (string) max(1, $activeTenantsCount),
                    'label' => $tenant !== null ? 'Governed & Audited Hiring' : 'Participating Organizations',
                ],
            ];
        }
        $payload['life_at_tringers']['renderable_metrics'] = $approvedMetrics;

        // 5. Filter employee stories: ONLY approved, consent_confirmed, published, non-expired stories
        // Never expose internal approval notes on public objects (§4.7)
        $maxCards = max(1, min(6, (int) ($payload['employee_stories']['max_cards'] ?? 3)));
        $quoteMax = max(60, min(600, (int) ($payload['employee_stories']['quote_max_length'] ?? 280)));
        $approvedStories = collect((array) ($payload['employee_stories']['stories'] ?? []))
            ->filter(function ($s) {
                if (! is_array($s) || empty($s['approved']) || empty($s['consent_confirmed']) || empty($s['published'])) {
                    return false;
                }
                if (trim((string) ($s['name'] ?? '')) === '' || trim((string) ($s['role'] ?? '')) === '' || trim((string) ($s['quote'] ?? '')) === '') {
                    return false;
                }
                if (! empty($s['expires_at']) && strtotime((string) $s['expires_at']) !== false && strtotime((string) $s['expires_at']) < time()) {
                    return false;
                }

                return true;
            })
            ->sortBy(fn ($s) => (int) ($s['order'] ?? 99))
            ->take($maxCards)
            ->map(fn ($s) => [
                'id' => (string) ($s['id'] ?? ''),
                'name' => (string) $s['name'],
                'role' => (string) $s['role'],
                'location' => (string) ($s['location'] ?? ''),
                'quote' => Str::limit((string) $s['quote'], $quoteMax),
                'avatar_url' => $this->isValidAssetUrl((string) ($s['avatar_url'] ?? '')) ? (string) $s['avatar_url'] : '',
                'avatar_alt' => (string) ($s['avatar_alt'] ?? ('Portrait of '.$s['name'])),
            ])
            ->values()
            ->all();
        $payload['employee_stories']['renderable_stories'] = $approvedStories;

        // 6. Filter footer links & social links (hide social links without approved https:// destination)
        $payload['footer']['renderable_quick_links'] = collect((array) ($payload['footer']['quick_links'] ?? []))
            ->filter(fn ($l) => is_array($l) && ! empty($l['visible']) && ! empty($l['label']) && $this->isValidNavigationUrl((string) ($l['url'] ?? '')))
            ->sortBy(fn ($l) => (int) ($l['order'] ?? 99))
            ->values()
            ->all();

        $payload['footer']['renderable_useful_links'] = collect((array) ($payload['footer']['useful_links'] ?? []))
            ->filter(fn ($l) => is_array($l) && ! empty($l['visible']) && ! empty($l['label']) && $this->isValidNavigationUrl((string) ($l['url'] ?? '')))
            ->sortBy(fn ($l) => (int) ($l['order'] ?? 99))
            ->values()
            ->all();

        $payload['footer']['renderable_social_links'] = collect((array) ($payload['footer']['social_links'] ?? []))
            ->filter(fn ($s) => is_array($s)
                && ! empty($s['approved'])
                && ! empty($s['visible'])
                && ! empty($s['url'])
                && $this->isValidExternalHttpsUrl((string) $s['url']))
            ->values()
            ->all();

        $payload['footer']['renderable_legal_links'] = collect((array) ($payload['footer']['legal_links'] ?? []))
            ->filter(fn ($l) => is_array($l) && ! empty($l['visible']) && ! empty($l['label']) && $this->isValidNavigationUrl((string) ($l['url'] ?? '')))
            ->values()
            ->all();

        return $payload;
    }

    /**
     * Computes dynamic category cards with real opening counts from eligible published jobs (§4.4).
     *
     * @param  array<string, mixed>  $resolvedPayload
     * @return array<int, array<string, mixed>>
     */
    public function computeCategoryCardsWithCounts(array $resolvedPayload, ?Tenant $tenant = null): array
    {
        $eligibleJobs = $this->baseEligibleJobsQuery($tenant)
            ->with('department')
            ->get();

        $categoriesConfig = collect((array) ($resolvedPayload['job_categories']['categories'] ?? []))
            ->filter(fn ($c) => is_array($c) && ! empty($c['visible']) && ! empty($c['name']) && ! empty($c['slug']))
            ->sortBy(fn ($c) => (int) ($c['order'] ?? 99))
            ->values();

        $emptyPolicy = (string) ($resolvedPayload['job_categories']['empty_category_policy'] ?? 'show_zero');
        $cards = [];

        foreach ($categoriesConfig as $cat) {
            $slug = (string) $cat['slug'];
            $deptCodes = array_map('strtoupper', (array) ($cat['department_codes'] ?? []));
            $keywords = array_map('strtolower', (array) ($cat['keywords'] ?? []));

            $count = $eligibleJobs->filter(function (JobPosting $job) use ($slug, $deptCodes, $keywords) {
                return $this->jobMatchesCategory($job, $slug, $deptCodes, $keywords);
            })->count();

            if ($count === 0 && $emptyPolicy === 'hide_empty') {
                continue;
            }

            $accentColors = match ((string) ($cat['accent'] ?? 'blue')) {
                'purple' => ['bg' => '#F5F3FF', 'fg' => '#7C3AED'],
                'teal' => ['bg' => '#ECFDF5', 'fg' => '#059669'],
                'amber' => ['bg' => '#FFFBEB', 'fg' => '#D97706'],
                'rose' => ['bg' => '#FFF1F2', 'fg' => '#E11D48'],
                'slate' => ['bg' => '#F0FDFA', 'fg' => '#0D9488'],
                default => ['bg' => '#EFF6FF', 'fg' => '#1D63ED'],
            };

            $cards[] = [
                'id' => (string) ($cat['id'] ?? $slug),
                'slug' => $slug,
                'name' => (string) $cat['name'],
                'icon' => in_array((string) ($cat['icon'] ?? 'code'), self::APPROVED_ICONS, true) ? (string) $cat['icon'] : 'code',
                'accent' => (string) ($cat['accent'] ?? 'blue'),
                'bg_tint' => (string) ($cat['bg_tint'] ?? $accentColors['bg']),
                'icon_color' => (string) ($cat['icon_color'] ?? $accentColors['fg']),
                'count' => $count,
                'open_count' => $count,
                'url' => $tenant !== null
                    ? route('tenant.careers.index', ['tenantSlug' => $tenant->slug, 'category' => $slug])
                    : route('careers.index', ['category' => $slug]),
            ];
        }

        return $cards;
    }

    /**
     * Determines whether an eligible job belongs to a configured category slug.
     *
     * @param  array<int, string>  $deptCodes
     * @param  array<int, string>  $keywords
     */
    public function jobMatchesCategory(JobPosting $job, string $categorySlug, array $deptCodes = [], array $keywords = []): bool
    {
        if (! empty($job->category_slug) && strtolower((string) $job->category_slug) === strtolower($categorySlug)) {
            return true;
        }

        $jobDeptCode = strtoupper((string) ($job->department?->code ?? ''));
        if ($jobDeptCode !== '' && in_array($jobDeptCode, $deptCodes, true)) {
            return true;
        }

        $haystack = strtolower($job->title.' '.($job->department?->name ?? ''));
        foreach ($keywords as $kw) {
            if ($kw !== '' && str_contains($haystack, strtolower($kw))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves Featured Jobs from real, currently eligible published jobs (§4.5).
     * Revalidates manual selections at render time so closed, draft, expired, or cross-tenant jobs are never shown.
     *
     * @param  array<string, mixed>  $resolvedPayload
     * @return EloquentCollection<int, JobPosting>
     */
    public function resolveFeaturedJobs(array $resolvedPayload, ?Tenant $tenant = null): Collection
    {
        $featuredCfg = (array) ($resolvedPayload['featured_jobs'] ?? []);
        $mode = (string) ($featuredCfg['selection_mode'] ?? 'hybrid');
        $maxItems = max(1, min(12, (int) ($featuredCfg['max_items'] ?? 5)));
        $manualIds = array_values(array_unique(array_filter(array_map('intval', (array) ($featuredCfg['manual_job_ids'] ?? [])))));

        $baseQuery = fn () => $this->baseEligibleJobsQuery($tenant)->with(['tenant', 'department', 'location']);

        if ($mode === 'manual') {
            if (empty($manualIds)) {
                return collect();
            }

            $jobs = $baseQuery()->whereIn('id', $manualIds)->get()->keyBy('id');
            $ordered = collect();
            foreach ($manualIds as $id) {
                if ($jobs->has($id)) {
                    $ordered->push($jobs->get($id));
                }
            }

            return $ordered->take($maxItems)->values();
        }

        if ($mode === 'featured_flag') {
            return $baseQuery()
                ->where('is_featured', true)
                ->orderByDesc('published_at')
                ->limit($maxItems)
                ->get();
        }

        if ($mode === 'recent') {
            return $baseQuery()
                ->orderByDesc('published_at')
                ->limit($maxItems)
                ->get();
        }

        // Default 'hybrid': manual eligible IDs first -> is_featured = true -> most recent published jobs
        $selected = collect();

        if (! empty($manualIds)) {
            $manualJobs = $baseQuery()->whereIn('id', $manualIds)->get()->keyBy('id');
            foreach ($manualIds as $id) {
                if ($manualJobs->has($id) && $selected->count() < $maxItems) {
                    $selected->push($manualJobs->get($id));
                }
            }
        }

        if ($selected->count() < $maxItems) {
            $excludeIds = $selected->pluck('id')->all();
            $flagged = $baseQuery()
                ->where('is_featured', true)
                ->when(! empty($excludeIds), fn ($q) => $q->whereNotIn('id', $excludeIds))
                ->orderByDesc('published_at')
                ->limit($maxItems - $selected->count())
                ->get();
            foreach ($flagged as $job) {
                $selected->push($job);
            }
        }

        if ($selected->count() < $maxItems) {
            $excludeIds = $selected->pluck('id')->all();
            $recent = $baseQuery()
                ->when(! empty($excludeIds), fn ($q) => $q->whereNotIn('id', $excludeIds))
                ->orderByDesc('published_at')
                ->limit($maxItems - $selected->count())
                ->get();
            foreach ($recent as $job) {
                $selected->push($job);
            }
        }

        return $selected->values();
    }

    public function baseEligibleJobsQuery(?Tenant $tenant = null)
    {
        return JobPosting::withoutTenantScope()
            ->whereHas('tenant', fn ($q) => $q->where('status', 'ACTIVE'))
            ->when($tenant !== null, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->where('status', JobPosting::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Updates the draft configuration with optimistic concurrency control, server-side sanitization,
     * and validation without modifying the live published portal (§6.3, §7).
     *
     * @param  array<string, mixed>  $sectionUpdates
     */
    public function saveDraft(
        CareerPortalConfiguration $config,
        array $sectionUpdates,
        User $actor,
        ?int $expectedDraftVersion = null,
        ?string $changeSummary = null,
    ): CareerPortalConfiguration {
        if (! $actor->canConfigureCareerPortal()) {
            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.draft_save_denied',
                outcome: 'DENIED',
                errorCode: 'CAREER_PORTAL_PERMISSION_REQUIRED',
                actorUserId: $actor->id,
            );
            abort(403, 'CAREER_PORTAL_PERMISSION_REQUIRED: You are not authorized to configure the Career Portal.');
        }

        return DB::transaction(function () use ($config, $sectionUpdates, $actor, $expectedDraftVersion, $changeSummary) {
            /** @var CareerPortalConfiguration $locked */
            $locked = CareerPortalConfiguration::query()->whereKey($config->id)->lockForUpdate()->firstOrFail();

            if ($expectedDraftVersion !== null && (int) $locked->draft_version !== (int) $expectedDraftVersion) {
                $this->audit->record(
                    module: 'career_portal',
                    action: 'career_portal.concurrent_conflict',
                    outcome: 'DENIED',
                    errorCode: 'CONCURRENT_MODIFICATION_CONFLICT',
                    entityType: 'CareerPortalConfiguration',
                    entityId: (string) $locked->id,
                    metadata: [
                        'expected_draft_version' => $expectedDraftVersion,
                        'actual_draft_version' => $locked->draft_version,
                    ],
                    actorUserId: $actor->id,
                );

                throw new RuntimeException('CONCURRENT_MODIFICATION_CONFLICT: This draft was modified by another session (expected draft v'.$expectedDraftVersion.', found v'.$locked->draft_version.'). Reload to review the latest draft.');
            }

            $currentDraft = $this->mergeWithDefaults((array) ($locked->draft_payload ?? []));
            $sanitizedDraft = $this->sanitizeAndMergeDraft($currentDraft, $sectionUpdates);
            $validation = $this->validateDraftPayload($sanitizedDraft, $locked->tenant);

            $locked->forceFill([
                'draft_version' => ((int) $locked->draft_version) + 1,
                'draft_payload' => $sanitizedDraft,
                'validation_status' => $validation['valid'] ? 'VALID' : 'INVALID',
                'validation_errors' => $validation['errors'],
                'last_validated_at' => now(),
                'updated_by_user_id' => $actor->id,
            ])->save();

            CareerPortalPublicationEvent::create([
                'configuration_id' => $locked->id,
                'version_id' => $locked->active_published_version_id,
                'tenant_id' => $locked->tenant_id,
                'action' => 'DRAFT_SAVED',
                'actor_user_id' => $actor->id,
                'actor_identifier' => $actor->email,
                'change_summary' => $this->sanitizePlainText($changeSummary ?? 'Updated career portal draft configuration.', 450),
                'metadata' => [
                    'draft_version' => $locked->draft_version,
                    'validation_status' => $locked->validation_status,
                    'updated_sections' => array_keys($sectionUpdates),
                ],
            ]);

            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.draft_saved',
                outcome: 'SUCCESS',
                entityType: 'CareerPortalConfiguration',
                entityId: (string) $locked->id,
                metadata: [
                    'draft_version' => $locked->draft_version,
                    'validation_status' => $locked->validation_status,
                    'updated_sections' => array_keys($sectionUpdates),
                ],
                tenantId: $locked->tenant_id,
                actorUserId: $actor->id,
            );

            return $locked->fresh();
        });
    }

    /**
     * Resets the editable draft to the approved Tringers Default Theme without modifying the live published portal (§5.2, §7).
     */
    public function resetDraftToDefault(
        CareerPortalConfiguration $config,
        User $actor,
        ?int $expectedDraftVersion = null,
    ): CareerPortalConfiguration {
        if (! $actor->canConfigureCareerPortal()) {
            abort(403, 'CAREER_PORTAL_PERMISSION_REQUIRED: You are not authorized to reset Career Portal configuration.');
        }

        return DB::transaction(function () use ($config, $actor, $expectedDraftVersion) {
            /** @var CareerPortalConfiguration $locked */
            $locked = CareerPortalConfiguration::query()->whereKey($config->id)->lockForUpdate()->firstOrFail();

            if ($expectedDraftVersion !== null && (int) $locked->draft_version !== (int) $expectedDraftVersion) {
                throw new RuntimeException('CONCURRENT_MODIFICATION_CONFLICT: Draft version mismatch.');
            }

            $defaultPayload = $this->getTringersDefaultPayload();

            $locked->forceFill([
                'draft_version' => ((int) $locked->draft_version) + 1,
                'draft_payload' => $defaultPayload,
                'validation_status' => 'VALID',
                'validation_errors' => [],
                'last_validated_at' => now(),
                'updated_by_user_id' => $actor->id,
            ])->save();

            CareerPortalPublicationEvent::create([
                'configuration_id' => $locked->id,
                'version_id' => $locked->active_published_version_id,
                'tenant_id' => $locked->tenant_id,
                'action' => 'DRAFT_RESET_DEFAULT',
                'actor_user_id' => $actor->id,
                'actor_identifier' => $actor->email,
                'change_summary' => 'Reset draft to approved Tringers default theme and content.',
                'metadata' => ['draft_version' => $locked->draft_version],
            ]);

            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.draft_reset_to_default',
                outcome: 'SUCCESS',
                entityType: 'CareerPortalConfiguration',
                entityId: (string) $locked->id,
                metadata: ['draft_version' => $locked->draft_version],
                tenantId: $locked->tenant_id,
                actorUserId: $actor->id,
            );

            return $locked->fresh();
        });
    }

    /**
     * Atomically validates and publishes the current draft, creating an immutable version and invalidating public caches (§6.3, §7).
     */
    public function publishDraft(
        CareerPortalConfiguration $config,
        User $actor,
        ?int $expectedDraftVersion = null,
        ?string $changeSummary = null,
    ): CareerPortalConfigurationVersion {
        if (! $actor->canPublishCareerPortal()) {
            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.publish_denied',
                outcome: 'DENIED',
                errorCode: 'CAREER_PORTAL_PUBLISH_PERMISSION_REQUIRED',
                actorUserId: $actor->id,
            );
            abort(403, 'CAREER_PORTAL_PUBLISH_PERMISSION_REQUIRED: You are not authorized to publish Career Portal configurations.');
        }

        return DB::transaction(function () use ($config, $actor, $expectedDraftVersion, $changeSummary) {
            /** @var CareerPortalConfiguration $locked */
            $locked = CareerPortalConfiguration::query()->whereKey($config->id)->lockForUpdate()->firstOrFail();

            if ($expectedDraftVersion !== null && (int) $locked->draft_version !== (int) $expectedDraftVersion) {
                throw new RuntimeException('CONCURRENT_MODIFICATION_CONFLICT: Draft was modified by another administrator before publication.');
            }

            $draftPayload = $this->mergeWithDefaults((array) ($locked->draft_payload ?? []));
            $validation = $this->validateDraftPayload($draftPayload, $locked->tenant);

            $locked->forceFill([
                'validation_status' => $validation['valid'] ? 'VALID' : 'INVALID',
                'validation_errors' => $validation['errors'],
                'last_validated_at' => now(),
            ])->save();

            if (! $validation['valid']) {
                $this->audit->record(
                    module: 'career_portal',
                    action: 'career_portal.publish_blocked_validation_failed',
                    outcome: 'DENIED',
                    errorCode: 'CAREER_PORTAL_VALIDATION_FAILED',
                    entityType: 'CareerPortalConfiguration',
                    entityId: (string) $locked->id,
                    metadata: ['error_keys' => array_keys($validation['errors'])],
                    tenantId: $locked->tenant_id,
                    actorUserId: $actor->id,
                );

                throw ValidationException::withMessages($validation['errors']);
            }

            $nextVersionNumber = ((int) CareerPortalConfigurationVersion::query()
                ->where('configuration_id', $locked->id)
                ->max('version_number')) + 1;

            $summary = $this->sanitizePlainText($changeSummary ?: "Published career portal configuration v{$nextVersionNumber}.", 450);

            $newVersion = CareerPortalConfigurationVersion::create([
                'configuration_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'version_number' => $nextVersionNumber,
                'status' => 'PUBLISHED',
                'schema_version' => self::SCHEMA_VERSION,
                'snapshot' => $draftPayload,
                'validation_summary' => $validation,
                'change_summary' => $summary,
                'created_by_user_id' => $actor->id,
            ]);

            $locked->forceFill([
                'status' => CareerPortalConfiguration::STATUS_PUBLISHED,
                'active_published_version_id' => $newVersion->id,
                'published_payload' => $draftPayload,
                'published_version_number' => $nextVersionNumber,
                'published_at' => now(),
                'published_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ])->save();

            CareerPortalPublicationEvent::create([
                'configuration_id' => $locked->id,
                'version_id' => $newVersion->id,
                'tenant_id' => $locked->tenant_id,
                'action' => 'PUBLISHED',
                'actor_user_id' => $actor->id,
                'actor_identifier' => $actor->email,
                'change_summary' => $summary,
                'metadata' => [
                    'published_version_number' => $nextVersionNumber,
                    'draft_version' => $locked->draft_version,
                ],
            ]);

            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.published',
                outcome: 'SUCCESS',
                entityType: 'CareerPortalConfigurationVersion',
                entityId: (string) $newVersion->id,
                metadata: [
                    'configuration_id' => $locked->id,
                    'version_number' => $nextVersionNumber,
                    'change_summary' => $summary,
                ],
                tenantId: $locked->tenant_id,
                actorUserId: $actor->id,
            );

            $this->invalidatePublicCaches($locked->tenant_id);

            return $newVersion;
        });
    }

    /**
     * Restores an earlier immutable version as a new publication event without rewriting historical records (§6.3, §7, §20).
     */
    public function rollbackToVersion(
        CareerPortalConfiguration $config,
        int $targetVersionNumber,
        User $actor,
        string $reason,
    ): CareerPortalConfigurationVersion {
        if (! $actor->canPublishCareerPortal()) {
            abort(403, 'CAREER_PORTAL_PUBLISH_PERMISSION_REQUIRED: You are not authorized to roll back Career Portal configurations.');
        }

        return DB::transaction(function () use ($config, $targetVersionNumber, $actor, $reason) {
            /** @var CareerPortalConfiguration $locked */
            $locked = CareerPortalConfiguration::query()->whereKey($config->id)->lockForUpdate()->firstOrFail();

            /** @var CareerPortalConfigurationVersion $targetVersion */
            $targetVersion = CareerPortalConfigurationVersion::query()
                ->where('configuration_id', $locked->id)
                ->where('version_number', $targetVersionNumber)
                ->firstOrFail();

            $restoredSnapshot = $this->mergeWithDefaults((array) $targetVersion->snapshot);
            $validation = $this->validateDraftPayload($restoredSnapshot, $locked->tenant);

            if (! $validation['valid']) {
                throw ValidationException::withMessages($validation['errors']);
            }

            $nextVersionNumber = ((int) CareerPortalConfigurationVersion::query()
                ->where('configuration_id', $locked->id)
                ->max('version_number')) + 1;

            $summary = $this->sanitizePlainText("Rollback to v{$targetVersionNumber}: {$reason}", 450);

            $newVersion = CareerPortalConfigurationVersion::create([
                'configuration_id' => $locked->id,
                'tenant_id' => $locked->tenant_id,
                'version_number' => $nextVersionNumber,
                'status' => 'PUBLISHED',
                'schema_version' => self::SCHEMA_VERSION,
                'snapshot' => $restoredSnapshot,
                'validation_summary' => $validation,
                'change_summary' => $summary,
                'created_by_user_id' => $actor->id,
            ]);

            $locked->forceFill([
                'status' => CareerPortalConfiguration::STATUS_PUBLISHED,
                'draft_version' => ((int) $locked->draft_version) + 1,
                'draft_payload' => $restoredSnapshot,
                'validation_status' => 'VALID',
                'validation_errors' => [],
                'last_validated_at' => now(),
                'active_published_version_id' => $newVersion->id,
                'published_payload' => $restoredSnapshot,
                'published_version_number' => $nextVersionNumber,
                'published_at' => now(),
                'published_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ])->save();

            CareerPortalPublicationEvent::create([
                'configuration_id' => $locked->id,
                'version_id' => $newVersion->id,
                'restored_from_version_id' => $targetVersion->id,
                'tenant_id' => $locked->tenant_id,
                'action' => 'ROLLED_BACK',
                'actor_user_id' => $actor->id,
                'actor_identifier' => $actor->email,
                'change_summary' => $summary,
                'metadata' => [
                    'restored_from_version_number' => $targetVersionNumber,
                    'new_version_number' => $nextVersionNumber,
                ],
            ]);

            $this->audit->record(
                module: 'career_portal',
                action: 'career_portal.rolled_back',
                outcome: 'SUCCESS',
                entityType: 'CareerPortalConfigurationVersion',
                entityId: (string) $newVersion->id,
                metadata: [
                    'restored_from_version_number' => $targetVersionNumber,
                    'new_version_number' => $nextVersionNumber,
                    'reason' => $summary,
                ],
                tenantId: $locked->tenant_id,
                actorUserId: $actor->id,
            );

            $this->invalidatePublicCaches($locked->tenant_id);

            return $newVersion;
        });
    }

    public function generatePreviewToken(CareerPortalConfiguration $config, User $actor, int $ttlMinutes = 30): string
    {
        if (! $actor->canConfigureCareerPortal()) {
            abort(403, 'CAREER_PORTAL_PERMISSION_REQUIRED: You are not authorized to preview Career Portal drafts.');
        }

        $plainToken = bin2hex(random_bytes(24));
        $config->forceFill([
            'preview_token_hash' => hash('sha256', $plainToken),
            'preview_token_expires_at' => now()->addMinutes($ttlMinutes),
        ])->save();

        CareerPortalPublicationEvent::create([
            'configuration_id' => $config->id,
            'version_id' => $config->active_published_version_id,
            'tenant_id' => $config->tenant_id,
            'action' => 'PREVIEW_GENERATED',
            'actor_user_id' => $actor->id,
            'actor_identifier' => $actor->email,
            'change_summary' => 'Generated short-lived non-indexable draft preview token.',
            'metadata' => ['ttl_minutes' => $ttlMinutes],
        ]);

        return $plainToken;
    }

    public function validatePreviewToken(CareerPortalConfiguration $config, ?string $plainToken): bool
    {
        if (! is_string($plainToken) || trim($plainToken) === '' || empty($config->preview_token_hash) || empty($config->preview_token_expires_at)) {
            return false;
        }

        if ($config->preview_token_expires_at->isPast()) {
            return false;
        }

        return hash_equals((string) $config->preview_token_hash, hash('sha256', $plainToken));
    }

    public function storeUploadedAsset(
        UploadedFile $file,
        string $title,
        string $altText,
        int $focalPointX,
        int $focalPointY,
        User $actor,
    ): CareerPortalAsset {
        if (! $actor->canConfigureCareerPortal()) {
            abort(403, 'CAREER_PORTAL_PERMISSION_REQUIRED: You are not authorized to upload Career Portal assets.');
        }

        $mime = (string) $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw ValidationException::withMessages([
                'asset_file' => ['UNSAFE_ASSET_TYPE: Only verified JPEG, PNG, and WebP images are permitted. SVG and executable files are prohibited.'],
            ]);
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $filename = 'portal-'.now()->format('YmdHis').'-'.Str::lower(Str::random(8)).'.'.$ext;
        $targetDir = public_path('images/career-portal');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $sizeBytes = (int) $file->getSize();
        $dimensions = @getimagesize($file->getRealPath() ?: '');
        $width = is_array($dimensions) ? (int) ($dimensions[0] ?? 0) : null;
        $height = is_array($dimensions) ? (int) ($dimensions[1] ?? 0) : null;

        $file->move($targetDir, $filename);
        @chmod($targetDir.'/'.$filename, 0644);

        $publicPath = '/images/career-portal/'.$filename;

        $asset = CareerPortalAsset::create([
            'tenant_id' => null,
            'asset_key' => pathinfo($filename, PATHINFO_FILENAME),
            'title' => $this->sanitizePlainText($title, 150),
            'alt_text' => $this->sanitizePlainText($altText, 240),
            'public_path' => $publicPath,
            'mime_type' => $mime,
            'width' => $width ?: null,
            'height' => $height ?: null,
            'size_bytes' => $sizeBytes,
            'focal_point_x' => max(0, min(100, $focalPointX)),
            'focal_point_y' => max(0, min(100, $focalPointY)),
            'approval_status' => 'APPROVED',
            'uploaded_by_user_id' => $actor->id,
        ]);

        $this->audit->record(
            module: 'career_portal',
            action: 'career_portal.asset_uploaded',
            outcome: 'SUCCESS',
            entityType: 'CareerPortalAsset',
            entityId: (string) $asset->id,
            metadata: [
                'public_path' => $publicPath,
                'mime_type' => $mime,
                'size_bytes' => $sizeBytes,
            ],
            actorUserId: $actor->id,
        );

        return $asset;
    }

    public function invalidatePublicCaches(?int $tenantId = null): void
    {
        Cache::forget(self::CACHE_KEY_GLOBAL);

        if ($tenantId !== null) {
            Cache::forget(self::CACHE_KEY_TENANT_PREFIX.$tenantId);
        } elseif (Schema::hasTable('tenants')) {
            foreach (Tenant::query()->pluck('id') as $id) {
                Cache::forget(self::CACHE_KEY_TENANT_PREFIX.$id);
            }
        }
    }

    /**
     * Validates the draft configuration across structure, URLs, WCAG 2.2 AA contrast,
     * image alt text, manual job eligibility, verified metrics, and approved testimonials (§6.3, §11, §12, §13).
     *
     * @param  array<string, mixed>  $payload
     * @return array{valid: bool, errors: array<string, string>}
     */
    public function validateDraftPayload(array $payload, ?Tenant $tenant = null): array
    {
        $errors = [];

        // 1. Required fields
        $requiredStrings = [
            'brand_theme.brand_name' => 'Brand name is required.',
            'hero_search.headline' => 'Hero headline is required.',
            'hero_search.search_button_label' => 'Hero search button label is required.',
            'featured_jobs.heading' => 'Featured jobs heading is required.',
            'life_at_tringers.heading' => 'Life at Tringers heading is required.',
            'final_cta.heading' => 'Final CTA banner heading is required.',
            'seo.default_title' => 'SEO default page title is required.',
            'seo.default_description' => 'SEO meta description is required.',
        ];

        foreach ($requiredStrings as $key => $message) {
            if (trim((string) data_get($payload, $key, '')) === '') {
                $errors[$key] = $message;
            }
        }

        // 2. Section order validation
        $sectionOrder = (array) data_get($payload, 'overview.section_order', []);
        if (empty($sectionOrder)) {
            $errors['overview.section_order'] = 'At least one portal homepage section must be ordered.';
        } else {
            foreach ($sectionOrder as $sec) {
                if (! in_array($sec, self::PERMITTED_SECTIONS, true)) {
                    $errors['overview.section_order'] = "Invalid section identifier '{$sec}' in section_order.";
                    break;
                }
            }
            if (count($sectionOrder) !== count(array_unique($sectionOrder))) {
                $errors['overview.section_order'] = 'Duplicate section identifiers are not permitted in section_order.';
            }
        }

        // 3. WCAG 2.2 AA contrast validation (§5.2, §12)
        $minContrast = (float) data_get($payload, 'accessibility.min_contrast_ratio', 4.5);
        $surfaceColor = (string) data_get($payload, 'brand_theme.surface_color', '#FFFFFF');
        $headingColor = (string) data_get($payload, 'brand_theme.heading_color', '#0F172A');
        $bodyColor = (string) data_get($payload, 'brand_theme.body_color', '#334155');
        $mutedColor = (string) data_get($payload, 'brand_theme.muted_color', '#475569');
        $primaryColor = (string) data_get($payload, 'brand_theme.primary_color', '#0B5ED7');

        if ($this->contrastRatio($headingColor, $surfaceColor) < $minContrast) {
            $errors['brand_theme.heading_color'] = sprintf(
                'WCAG_CONTRAST_FAILURE: Heading color (%s) against surface (%s) has contrast %.2f:1 (minimum %.1f:1 required).',
                $headingColor,
                $surfaceColor,
                $this->contrastRatio($headingColor, $surfaceColor),
                $minContrast
            );
        }

        if ($this->contrastRatio($bodyColor, $surfaceColor) < $minContrast) {
            $errors['brand_theme.body_color'] = sprintf(
                'WCAG_CONTRAST_FAILURE: Body text color (%s) against surface (%s) has contrast %.2f:1 (minimum %.1f:1 required).',
                $bodyColor,
                $surfaceColor,
                $this->contrastRatio($bodyColor, $surfaceColor),
                $minContrast
            );
        }

        if ($this->contrastRatio($mutedColor, $surfaceColor) < $minContrast) {
            $errors['brand_theme.muted_color'] = sprintf(
                'WCAG_CONTRAST_FAILURE: Muted text color (%s) against surface (%s) has contrast %.2f:1 (minimum %.1f:1 required).',
                $mutedColor,
                $surfaceColor,
                $this->contrastRatio($mutedColor, $surfaceColor),
                $minContrast
            );
        }

        if ($this->contrastRatio('#FFFFFF', $primaryColor) < 4.5) {
            $errors['brand_theme.primary_color'] = sprintf(
                'WCAG_CONTRAST_FAILURE: Primary accent color (%s) with white button text has contrast %.2f:1 (minimum 4.5:1 required).',
                $primaryColor,
                $this->contrastRatio('#FFFFFF', $primaryColor)
            );
        }

        // 4. Image alt text validation when required (§12)
        if ((bool) data_get($payload, 'accessibility.require_image_alt_text', true)) {
            if (trim((string) data_get($payload, 'hero_search.image_alt', '')) === '') {
                $errors['hero_search.image_alt'] = 'Accessible alternative text is required for the hero image.';
            }
            if (trim((string) data_get($payload, 'life_at_tringers.image_alt', '')) === '') {
                $errors['life_at_tringers.image_alt'] = 'Accessible alternative text is required for the Life at Tringers photograph.';
            }
            if (trim((string) data_get($payload, 'final_cta.image_alt', '')) === '') {
                $errors['final_cta.image_alt'] = 'Accessible alternative text is required for the Final CTA banner image.';
            }
        }

        // 5. Navigation, CTA, and Footer URL validation (§4.1, §4.9, §13)
        foreach ((array) data_get($payload, 'header_navigation.nav_items', []) as $idx => $item) {
            $url = (string) ($item['url'] ?? '');
            if (! $this->isValidNavigationUrl($url)) {
                $errors["header_navigation.nav_items.{$idx}.url"] = "Unsafe or invalid navigation destination URL '{$url}'.";
            }
        }

        foreach (['header_navigation.cta_url', 'life_at_tringers.cta_url', 'final_cta.cta_url'] as $ctaKey) {
            $url = (string) data_get($payload, $ctaKey, '');
            if (! $this->isValidNavigationUrl($url)) {
                $errors[$ctaKey] = "Unsafe or invalid CTA destination URL '{$url}'.";
            }
        }

        foreach (['quick_links', 'useful_links', 'legal_links'] as $footerGroup) {
            foreach ((array) data_get($payload, "footer.{$footerGroup}", []) as $idx => $link) {
                $url = (string) ($link['url'] ?? '');
                if (! $this->isValidNavigationUrl($url)) {
                    $errors["footer.{$footerGroup}.{$idx}.url"] = "Unsafe or invalid footer destination URL '{$url}'.";
                }
            }
        }

        foreach ((array) data_get($payload, 'footer.social_links', []) as $idx => $soc) {
            if (! empty($soc['visible']) || ! empty($soc['approved'])) {
                $url = (string) ($soc['url'] ?? '');
                if ($url !== '' && ! $this->isValidExternalHttpsUrl($url)) {
                    $errors["footer.social_links.{$idx}.url"] = 'Social link URL must be a valid https:// destination.';
                }
            }
        }

        // 6. Featured jobs manual selection eligibility validation (§4.5, §7)
        $manualIds = array_values(array_unique(array_filter(array_map('intval', (array) data_get($payload, 'featured_jobs.manual_job_ids', [])))));
        if (! empty($manualIds) && Schema::hasTable('job_postings')) {
            $eligibleCount = $this->baseEligibleJobsQuery($tenant)->whereIn('id', $manualIds)->count();
            if ($eligibleCount !== count($manualIds)) {
                $errors['featured_jobs.manual_job_ids'] = 'INELIGIBLE_FEATURED_JOB: One or more manually selected featured jobs are draft, closed, expired, or belong to an inactive/different tenant.';
            }
        }

        // 7. Culture metrics verification governance (§4.6, §7)
        foreach ((array) data_get($payload, 'life_at_tringers.metrics', []) as $idx => $metric) {
            if (! empty($metric['published'])) {
                if (empty($metric['approved'])) {
                    $errors["life_at_tringers.metrics.{$idx}.approved"] = 'UNAPPROVED_METRIC: A company metric cannot be published unless it is explicitly verified and approved.';
                }
                if (strlen(trim((string) ($metric['verification_note'] ?? ''))) < 5) {
                    $errors["life_at_tringers.metrics.{$idx}.verification_note"] = 'METRIC_VERIFICATION_REQUIRED: A verification source note is required before publishing a company metric.';
                }
            }
        }

        // 8. Employee stories approval & consent governance (§4.7, §7)
        foreach ((array) data_get($payload, 'employee_stories.stories', []) as $idx => $story) {
            if (! empty($story['published'])) {
                if (empty($story['approved']) || empty($story['consent_confirmed'])) {
                    $errors["employee_stories.stories.{$idx}.approved"] = 'UNAPPROVED_STORY: Employee stories require both administrative approval and confirmed employee consent before publication.';
                }
                if (trim((string) ($story['name'] ?? '')) === '' || trim((string) ($story['role'] ?? '')) === '' || trim((string) ($story['quote'] ?? '')) === '') {
                    $errors["employee_stories.stories.{$idx}.quote"] = 'INCOMPLETE_STORY: Published employee stories require name, role, and quotation.';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'contrast' => [
                'heading_on_surface' => $this->contrastRatio($headingColor, $surfaceColor),
                'body_on_surface' => $this->contrastRatio($bodyColor, $surfaceColor),
                'muted_on_surface' => $this->contrastRatio($mutedColor, $surfaceColor),
                'white_on_primary' => $this->contrastRatio('#FFFFFF', $primaryColor),
            ],
        ];
    }

    /**
     * Server-side sanitization and merging of section updates into the draft payload (§6.4, §13).
     *
     * @param  array<string, mixed>  $baseDraft
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    public function sanitizeAndMergeDraft(array $baseDraft, array $updates): array
    {
        $draft = $baseDraft;

        foreach ($updates as $section => $values) {
            if (! is_array($values)) {
                continue;
            }

            switch ($section) {
                case 'overview':
                    if (isset($values['portal_enabled'])) {
                        $draft['overview']['portal_enabled'] = (bool) $values['portal_enabled'];
                    }
                    if (isset($values['section_order']) && is_array($values['section_order'])) {
                        $draft['overview']['section_order'] = array_values(array_map(
                            fn ($s) => $this->sanitizePlainText((string) $s, 50),
                            $values['section_order']
                        ));
                    }
                    if (isset($values['section_visibility']) && is_array($values['section_visibility'])) {
                        foreach ($draft['overview']['section_visibility'] as $secKey => $currentVis) {
                            if (array_key_exists($secKey, $values['section_visibility'])) {
                                $draft['overview']['section_visibility'][$secKey] = (bool) $values['section_visibility'][$secKey];
                            }
                        }
                    }
                    break;

                case 'brand_theme':
                    foreach (['brand_name', 'logo_text', 'logo_subtext'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['brand_theme'][$k] = $this->sanitizePlainText((string) $values[$k], 80);
                        }
                    }
                    foreach (['logo_url', 'favicon_url'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $url = trim((string) $values[$k]);
                            $draft['brand_theme'][$k] = ($url === '' || $this->isValidAssetUrl($url)) ? $url : '';
                        }
                    }
                    foreach ([
                        'primary_color', 'secondary_color', 'heading_color', 'body_color',
                        'muted_color', 'surface_color', 'alt_surface_color', 'border_color',
                        'success_color', 'warning_color', 'error_color',
                    ] as $colorKey) {
                        if (array_key_exists($colorKey, $values)) {
                            $draft['brand_theme'][$colorKey] = $this->sanitizeHexColor(
                                (string) $values[$colorKey],
                                (string) ($draft['brand_theme'][$colorKey] ?? '#0B5ED7')
                            );
                        }
                    }
                    if (isset($values['font_family']) && in_array((string) $values['font_family'], self::APPROVED_FONTS, true)) {
                        $draft['brand_theme']['font_family'] = (string) $values['font_family'];
                    }
                    if (isset($values['base_font_size_px'])) {
                        $draft['brand_theme']['base_font_size_px'] = max(14, min(18, (int) $values['base_font_size_px']));
                    }
                    if (isset($values['content_max_width_px'])) {
                        $draft['brand_theme']['content_max_width_px'] = max(1080, min(1440, (int) $values['content_max_width_px']));
                    }
                    if (isset($values['section_spacing_px'])) {
                        $draft['brand_theme']['section_spacing_px'] = max(40, min(112, (int) $values['section_spacing_px']));
                    }
                    if (isset($values['card_radius_px'])) {
                        $draft['brand_theme']['card_radius_px'] = max(4, min(20, (int) $values['card_radius_px']));
                    }
                    if (isset($values['button_radius_px'])) {
                        $draft['brand_theme']['button_radius_px'] = max(4, min(16, (int) $values['button_radius_px']));
                    }
                    if (isset($values['card_shadow_preset']) && in_array((string) $values['card_shadow_preset'], ['none', 'subtle', 'elevated'], true)) {
                        $draft['brand_theme']['card_shadow_preset'] = (string) $values['card_shadow_preset'];
                    }
                    break;

                case 'header_navigation':
                    if (array_key_exists('sticky_header', $values)) {
                        $draft['header_navigation']['sticky_header'] = (bool) $values['sticky_header'];
                    }
                    if (array_key_exists('header_height_px', $values)) {
                        $draft['header_navigation']['header_height_px'] = max(56, min(96, (int) $values['header_navigation']['header_height_px']));
                    }
                    if (array_key_exists('cta_label', $values)) {
                        $draft['header_navigation']['cta_label'] = $this->sanitizePlainText((string) $values['cta_label'], 60);
                    }
                    if (array_key_exists('cta_url', $values)) {
                        $draft['header_navigation']['cta_url'] = $this->sanitizeUrlString((string) $values['cta_url']);
                    }
                    if (array_key_exists('cta_visible', $values)) {
                        $draft['header_navigation']['cta_visible'] = (bool) $values['cta_visible'];
                    }
                    if (isset($values['nav_items']) && is_array($values['nav_items'])) {
                        $items = [];
                        foreach (array_values($values['nav_items']) as $idx => $item) {
                            if (! is_array($item)) {
                                continue;
                            }
                            $items[] = [
                                'id' => $this->sanitizeIdentifier((string) ($item['id'] ?? ('nav-'.($idx + 1)))),
                                'label' => $this->sanitizePlainText((string) ($item['label'] ?? ''), 60),
                                'url' => $this->sanitizeUrlString((string) ($item['url'] ?? '')),
                                'target' => (($item['target'] ?? '_self') === '_blank') ? '_blank' : '_self',
                                'visible' => ! empty($item['visible']),
                                'order' => (int) ($item['order'] ?? ($idx + 1)),
                            ];
                        }
                        $draft['header_navigation']['nav_items'] = $items;
                    }
                    break;

                case 'hero_search':
                    foreach (['eyebrow', 'headline', 'supporting_text', 'search_placeholder', 'search_button_label', 'image_alt', 'popular_searches_label'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['hero_search'][$k] = $this->sanitizePlainText((string) $values[$k], 280);
                        }
                    }
                    foreach (['desktop_image_url', 'mobile_image_url'] as $imgKey) {
                        if (array_key_exists($imgKey, $values)) {
                            $url = trim((string) $values[$imgKey]);
                            if ($this->isValidAssetUrl($url)) {
                                $draft['hero_search'][$imgKey] = $url;
                            }
                        }
                    }
                    foreach (['show_category_filter', 'show_location_filter', 'show_work_mode_filter'] as $boolKey) {
                        if (array_key_exists($boolKey, $values)) {
                            $draft['hero_search'][$boolKey] = (bool) $values[$boolKey];
                        }
                    }
                    if (isset($values['focal_point_x'])) {
                        $draft['hero_search']['focal_point_x'] = max(0, min(100, (int) $values['focal_point_x']));
                    }
                    if (isset($values['focal_point_y'])) {
                        $draft['hero_search']['focal_point_y'] = max(0, min(100, (int) $values['focal_point_y']));
                    }
                    if (isset($values['overlay_opacity'])) {
                        $draft['hero_search']['overlay_opacity'] = max(0.35, min(0.85, round((float) $values['overlay_opacity'], 2)));
                    }
                    if (isset($values['min_height_px'])) {
                        $draft['hero_search']['min_height_px'] = max(360, min(680, (int) $values['min_height_px']));
                    }
                    if (isset($values['text_alignment']) && in_array((string) $values['text_alignment'], ['left', 'center'], true)) {
                        $draft['hero_search']['text_alignment'] = (string) $values['text_alignment'];
                    }
                    if (isset($values['popular_searches_mode']) && in_array((string) $values['popular_searches_mode'], ['execute', 'prefill'], true)) {
                        $draft['hero_search']['popular_searches_mode'] = (string) $values['popular_searches_mode'];
                    }
                    if (isset($values['popular_searches']) && is_array($values['popular_searches'])) {
                        $chips = [];
                        foreach (array_values($values['popular_searches']) as $idx => $chip) {
                            if (! is_array($chip)) {
                                continue;
                            }
                            $chips[] = [
                                'id' => $this->sanitizeIdentifier((string) ($chip['id'] ?? ('pop-'.($idx + 1)))),
                                'label' => $this->sanitizePlainText((string) ($chip['label'] ?? ''), 50),
                                'query' => $this->sanitizePlainText((string) ($chip['query'] ?? ''), 80),
                                'type' => in_array((string) ($chip['type'] ?? 'q'), ['q', 'work_mode', 'location', 'category'], true)
                                    ? (string) $chip['type']
                                    : 'q',
                                'visible' => ! empty($chip['visible']),
                                'order' => (int) ($chip['order'] ?? ($idx + 1)),
                            ];
                        }
                        $draft['hero_search']['popular_searches'] = $chips;
                    }
                    break;

                case 'value_propositions':
                    if (array_key_exists('section_heading', $values)) {
                        $draft['value_propositions']['section_heading'] = $this->sanitizePlainText((string) $values['section_heading'], 120);
                    }
                    if (array_key_exists('show_section_heading', $values)) {
                        $draft['value_propositions']['show_section_heading'] = (bool) $values['show_section_heading'];
                    }
                    if (array_key_exists('desktop_columns', $values)) {
                        $draft['value_propositions']['desktop_columns'] = max(2, min(4, (int) $values['desktop_columns']));
                    }
                    if (isset($values['items']) && is_array($values['items'])) {
                        $items = [];
                        foreach (array_values($values['items']) as $idx => $item) {
                            if (! is_array($item)) {
                                continue;
                            }
                            $icon = (string) ($item['icon'] ?? 'target');
                            $items[] = [
                                'id' => $this->sanitizeIdentifier((string) ($item['id'] ?? ('vp-'.($idx + 1)))),
                                'title' => $this->sanitizePlainText((string) ($item['title'] ?? ''), 80),
                                'description' => $this->sanitizePlainText((string) ($item['description'] ?? ''), 220),
                                'icon' => in_array($icon, self::APPROVED_ICONS, true) ? $icon : 'target',
                                'accent' => $this->sanitizePlainText((string) ($item['accent'] ?? 'blue'), 24),
                                'visible' => ! empty($item['visible']),
                                'order' => (int) ($item['order'] ?? ($idx + 1)),
                            ];
                        }
                        $draft['value_propositions']['items'] = $items;
                    }
                    break;

                case 'job_categories':
                    foreach (['heading', 'view_all_label'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['job_categories'][$k] = $this->sanitizePlainText((string) $values[$k], 100);
                        }
                    }
                    if (isset($values['empty_category_policy']) && in_array((string) $values['empty_category_policy'], ['show_zero', 'hide_empty'], true)) {
                        $draft['job_categories']['empty_category_policy'] = (string) $values['empty_category_policy'];
                    }
                    if (isset($values['categories']) && is_array($values['categories'])) {
                        $cats = [];
                        foreach (array_values($values['categories']) as $idx => $cat) {
                            if (! is_array($cat)) {
                                continue;
                            }
                            $slug = $this->sanitizeIdentifier((string) ($cat['slug'] ?? Str::slug((string) ($cat['name'] ?? 'category-'.($idx + 1)))));
                            $icon = (string) ($cat['icon'] ?? 'code');
                            $keywords = is_array($cat['keywords'] ?? null)
                                ? array_values(array_filter(array_map(fn ($v) => $this->sanitizePlainText((string) $v, 40), $cat['keywords'])))
                                : array_values(array_filter(array_map(fn ($v) => $this->sanitizePlainText(trim($v), 40), explode(',', (string) ($cat['keywords_csv'] ?? '')))));
                            $deptCodes = is_array($cat['department_codes'] ?? null)
                                ? array_values(array_filter(array_map(fn ($v) => strtoupper($this->sanitizePlainText((string) $v, 24)), $cat['department_codes'])))
                                : array_values(array_filter(array_map(fn ($v) => strtoupper($this->sanitizePlainText(trim($v), 24)), explode(',', (string) ($cat['department_codes_csv'] ?? '')))));

                            $cats[] = [
                                'id' => $this->sanitizeIdentifier((string) ($cat['id'] ?? ('cat-'.$slug))),
                                'slug' => $slug,
                                'name' => $this->sanitizePlainText((string) ($cat['name'] ?? ''), 80),
                                'icon' => in_array($icon, self::APPROVED_ICONS, true) ? $icon : 'code',
                                'accent' => $this->sanitizePlainText((string) ($cat['accent'] ?? 'blue'), 24),
                                'keywords' => $keywords,
                                'department_codes' => $deptCodes,
                                'visible' => ! empty($cat['visible']),
                                'order' => (int) ($cat['order'] ?? ($idx + 1)),
                            ];
                        }
                        $draft['job_categories']['categories'] = $cats;
                    }
                    break;

                case 'featured_jobs':
                    foreach (['heading', 'subtitle', 'view_all_label', 'empty_state_message'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['featured_jobs'][$k] = $this->sanitizePlainText((string) $values[$k], 160);
                        }
                    }
                    if (isset($values['selection_mode']) && in_array((string) $values['selection_mode'], ['manual', 'featured_flag', 'recent', 'hybrid'], true)) {
                        $draft['featured_jobs']['selection_mode'] = (string) $values['selection_mode'];
                    }
                    if (isset($values['max_items'])) {
                        $draft['featured_jobs']['max_items'] = max(1, min(12, (int) $values['max_items']));
                    }
                    if (isset($values['manual_job_ids']) && is_array($values['manual_job_ids'])) {
                        $draft['featured_jobs']['manual_job_ids'] = array_values(array_unique(array_filter(array_map('intval', $values['manual_job_ids']))));
                    }
                    if (array_key_exists('show_posting_age', $values)) {
                        $draft['featured_jobs']['show_posting_age'] = (bool) $values['show_posting_age'];
                    }
                    break;

                case 'life_at_tringers':
                    foreach (['eyebrow', 'heading', 'body', 'cta_label', 'image_alt'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['life_at_tringers'][$k] = $this->sanitizePlainText((string) $values[$k], 600);
                        }
                    }
                    if (array_key_exists('cta_url', $values)) {
                        $draft['life_at_tringers']['cta_url'] = $this->sanitizeUrlString((string) $values['cta_url']);
                    }
                    if (array_key_exists('image_url', $values) && $this->isValidAssetUrl((string) $values['image_url'])) {
                        $draft['life_at_tringers']['image_url'] = trim((string) $values['image_url']);
                    }
                    if (array_key_exists('show_live_verified_counts', $values)) {
                        $draft['life_at_tringers']['show_live_verified_counts'] = (bool) $values['show_live_verified_counts'];
                    }
                    if (isset($values['image_position']) && in_array((string) $values['image_position'], ['left', 'right'], true)) {
                        $draft['life_at_tringers']['image_position'] = (string) $values['image_position'];
                    }
                    if (isset($values['background_style']) && in_array((string) $values['background_style'], ['pale_blue', 'neutral', 'white'], true)) {
                        $draft['life_at_tringers']['background_style'] = (string) $values['background_style'];
                    }
                    if (isset($values['metrics']) && is_array($values['metrics'])) {
                        $metrics = [];
                        foreach (array_values($values['metrics']) as $idx => $m) {
                            if (! is_array($m)) {
                                continue;
                            }
                            $metrics[] = [
                                'id' => $this->sanitizeIdentifier((string) ($m['id'] ?? ('metric-'.($idx + 1)))),
                                'value' => $this->sanitizePlainText((string) ($m['value'] ?? ''), 40),
                                'label' => $this->sanitizePlainText((string) ($m['label'] ?? ''), 80),
                                'verification_note' => $this->sanitizePlainText((string) ($m['verification_note'] ?? ''), 250),
                                'approved' => ! empty($m['approved']),
                                'published' => ! empty($m['published']),
                                'order' => (int) ($m['order'] ?? ($idx + 1)),
                            ];
                        }
                        $draft['life_at_tringers']['metrics'] = $metrics;
                    }
                    break;

                case 'employee_stories':
                    foreach (['heading', 'view_more_label', 'fallback_heading', 'fallback_body'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['employee_stories'][$k] = $this->sanitizePlainText((string) $values[$k], 350);
                        }
                    }
                    if (isset($values['empty_behavior']) && in_array((string) $values['empty_behavior'], ['fallback_banner', 'hide_section'], true)) {
                        $draft['employee_stories']['empty_behavior'] = (string) $values['empty_behavior'];
                    }
                    if (isset($values['max_cards'])) {
                        $draft['employee_stories']['max_cards'] = max(1, min(6, (int) $values['max_cards']));
                    }
                    if (isset($values['stories']) && is_array($values['stories'])) {
                        $stories = [];
                        foreach (array_values($values['stories']) as $idx => $s) {
                            if (! is_array($s)) {
                                continue;
                            }
                            $stories[] = [
                                'id' => $this->sanitizeIdentifier((string) ($s['id'] ?? ('story-'.($idx + 1)))),
                                'name' => $this->sanitizePlainText((string) ($s['name'] ?? ''), 100),
                                'role' => $this->sanitizePlainText((string) ($s['role'] ?? ''), 120),
                                'location' => $this->sanitizePlainText((string) ($s['location'] ?? ''), 80),
                                'quote' => $this->sanitizePlainText((string) ($s['quote'] ?? ''), 400),
                                'avatar_url' => $this->isValidAssetUrl((string) ($s['avatar_url'] ?? '')) ? trim((string) ($s['avatar_url'] ?? '')) : '',
                                'avatar_alt' => $this->sanitizePlainText((string) ($s['avatar_alt'] ?? ''), 150),
                                'approved' => ! empty($s['approved']),
                                'consent_confirmed' => ! empty($s['consent_confirmed']),
                                'published' => ! empty($s['published']),
                                'order' => (int) ($s['order'] ?? ($idx + 1)),
                            ];
                        }
                        $draft['employee_stories']['stories'] = $stories;
                    }
                    break;

                case 'final_cta':
                    foreach (['eyebrow', 'heading', 'body', 'cta_label', 'image_alt'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['final_cta'][$k] = $this->sanitizePlainText((string) $values[$k], 350);
                        }
                    }
                    if (array_key_exists('cta_url', $values)) {
                        $draft['final_cta']['cta_url'] = $this->sanitizeUrlString((string) $values['cta_url']);
                    }
                    if (array_key_exists('image_url', $values) && $this->isValidAssetUrl((string) $values['image_url'])) {
                        $draft['final_cta']['image_url'] = trim((string) $values['image_url']);
                    }
                    if (isset($values['overlay_opacity'])) {
                        $draft['final_cta']['overlay_opacity'] = max(0.35, min(0.85, round((float) $values['overlay_opacity'], 2)));
                    }
                    if (isset($values['min_height_px'])) {
                        $draft['final_cta']['min_height_px'] = max(260, min(560, (int) $values['min_height_px']));
                    }
                    break;

                case 'footer':
                    foreach (['brand_name', 'brand_statement', 'copyright_holder', 'quick_links_heading', 'useful_links_heading', 'social_heading'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['footer'][$k] = $this->sanitizePlainText((string) $values[$k], 140);
                        }
                    }
                    foreach (['quick_links', 'useful_links', 'legal_links'] as $linkGroup) {
                        if (isset($values[$linkGroup]) && is_array($values[$linkGroup])) {
                            $links = [];
                            foreach (array_values($values[$linkGroup]) as $idx => $l) {
                                if (! is_array($l)) {
                                    continue;
                                }
                                $links[] = [
                                    'id' => $this->sanitizeIdentifier((string) ($l['id'] ?? ($linkGroup.'-'.($idx + 1)))),
                                    'label' => $this->sanitizePlainText((string) ($l['label'] ?? ''), 80),
                                    'url' => $this->sanitizeUrlString((string) ($l['url'] ?? '')),
                                    'visible' => ! empty($l['visible']),
                                    'order' => (int) ($l['order'] ?? ($idx + 1)),
                                ];
                            }
                            $draft['footer'][$linkGroup] = $links;
                        }
                    }
                    if (isset($values['social_links']) && is_array($values['social_links'])) {
                        $socials = [];
                        foreach (array_values($values['social_links']) as $idx => $s) {
                            if (! is_array($s)) {
                                continue;
                            }
                            $socials[] = [
                                'id' => $this->sanitizeIdentifier((string) ($s['id'] ?? ('soc-'.($idx + 1)))),
                                'platform' => $this->sanitizeIdentifier((string) ($s['platform'] ?? 'linkedin')),
                                'label' => $this->sanitizePlainText((string) ($s['label'] ?? ''), 40),
                                'url' => $this->sanitizeUrlString((string) ($s['url'] ?? '')),
                                'approved' => ! empty($s['approved']),
                                'visible' => ! empty($s['visible']),
                            ];
                        }
                        $draft['footer']['social_links'] = $socials;
                    }
                    break;

                case 'seo':
                    foreach (['default_title', 'default_description', 'og_title', 'og_description'] as $k) {
                        if (array_key_exists($k, $values)) {
                            $draft['seo'][$k] = $this->sanitizePlainText((string) $values[$k], 280);
                        }
                    }
                    if (isset($values['robots_policy']) && in_array((string) $values['robots_policy'], ['index,follow', 'noindex,nofollow', 'index,nofollow'], true)) {
                        $draft['seo']['robots_policy'] = (string) $values['robots_policy'];
                    }
                    foreach (['enable_organization_jsonld', 'enable_jobposting_jsonld'] as $boolKey) {
                        if (array_key_exists($boolKey, $values)) {
                            $draft['seo'][$boolKey] = (bool) $values[$boolKey];
                        }
                    }
                    break;

                case 'accessibility':
                    foreach (['respect_reduced_motion', 'require_image_alt_text', 'visible_focus_outline'] as $boolKey) {
                        if (array_key_exists($boolKey, $values)) {
                            $draft['accessibility'][$boolKey] = (bool) $values[$boolKey];
                        }
                    }
                    break;

                case 'analytics_consent':
                    foreach (['analytics_enabled', 'require_cookie_consent', 'consent_banner_enabled', 'redact_search_free_text'] as $boolKey) {
                        if (array_key_exists($boolKey, $values)) {
                            $draft['analytics_consent'][$boolKey] = (bool) $values[$boolKey];
                        }
                    }
                    if (array_key_exists('consent_banner_text', $values)) {
                        $draft['analytics_consent']['consent_banner_text'] = $this->sanitizePlainText((string) $values['consent_banner_text'], 350);
                    }
                    break;
            }
        }

        return $draft;
    }

    /**
     * Deep-merges stored configuration over the authoritative Tringers default payload.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public function mergeWithDefaults(array $stored): array
    {
        $defaults = $this->getTringersDefaultPayload();
        foreach ($defaults as $group => $groupDefaults) {
            if (! isset($stored[$group])) {
                $stored[$group] = $groupDefaults;

                continue;
            }
            if (is_array($groupDefaults) && is_array($stored[$group])) {
                foreach ($groupDefaults as $k => $v) {
                    if (! array_key_exists($k, $stored[$group])) {
                        $stored[$group][$k] = $v;
                    }
                }
            }
        }

        return $stored;
    }

    public function sanitizePlainText(string $input, int $maxLength = 255): string
    {
        $cleaned = preg_replace('/<(script|style|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $input) ?? $input;
        $cleaned = strip_tags($cleaned);
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned) ?? $cleaned;

        return Str::limit(trim($cleaned), $maxLength, '');
    }

    public function sanitizeIdentifier(string $input): string
    {
        $slug = strtolower(trim($input));
        $slug = preg_replace('/[^a-z0-9\-_]/', '-', $slug) ?? 'item';
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? 'item', '-');

        return $slug !== '' ? substr($slug, 0, 64) : 'item';
    }

    public function sanitizeHexColor(string $input, string $fallback): string
    {
        $trimmed = strtoupper(trim($input));
        if (preg_match('/^#[0-9A-F]{6}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return $fallback;
    }

    public function sanitizeUrlString(string $input): string
    {
        $trimmed = trim($input);
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $trimmed) ?? '';

        return substr($cleaned, 0, 255);
    }

    public function isValidNavigationUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return false;
        }

        $lower = strtolower($trimmed);
        foreach (['javascript:', 'data:', 'vbscript:', 'file:'] as $forbidden) {
            if (str_starts_with($lower, $forbidden)) {
                return false;
            }
        }

        // Prevent protocol-relative open redirects like //evil.example
        if (str_starts_with($trimmed, '//')) {
            return false;
        }

        if (str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/')) {
            return true;
        }

        return $this->isValidExternalHttpsUrl($trimmed);
    }

    public function isValidExternalHttpsUrl(string $url): bool
    {
        $trimmed = trim($url);
        if (! str_starts_with(strtolower($trimmed), 'https://')) {
            return false;
        }

        return filter_var($trimmed, FILTER_VALIDATE_URL) !== false;
    }

    public function isValidAssetUrl(string $url): bool
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return true;
        }

        // Never allow candidate document paths or private storage URLs in public portal assets (§9, §13)
        if (str_contains(strtolower($trimmed), '/documents/') || str_contains(strtolower($trimmed), 'private')) {
            return false;
        }

        if (str_starts_with($trimmed, '/images/') || str_starts_with($trimmed, '/favicon')) {
            return ! str_contains($trimmed, '..');
        }

        return $this->isValidExternalHttpsUrl($trimmed);
    }

    public function contrastRatio(string $hexA, string $hexB): float
    {
        $lumA = $this->relativeLuminance($hexA);
        $lumB = $this->relativeLuminance($hexB);
        $lighter = max($lumA, $lumB);
        $darker = min($lumA, $lumB);

        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }

    private function relativeLuminance(string $hex): float
    {
        $clean = ltrim(trim($hex), '#');
        if (strlen($clean) !== 6 || ! ctype_xdigit($clean)) {
            return 0.0;
        }

        $channels = [
            hexdec(substr($clean, 0, 2)) / 255,
            hexdec(substr($clean, 2, 2)) / 255,
            hexdec(substr($clean, 4, 2)) / 255,
        ];

        $linear = array_map(
            fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $channels
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }
}
