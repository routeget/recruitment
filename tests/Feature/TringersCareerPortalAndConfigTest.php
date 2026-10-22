<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CareerPortalConfiguration;
use App\Models\CareerPortalConfigurationVersion;
use App\Models\CareerPortalPublicationEvent;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class TringersCareerPortalAndConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);
    }

    public function test_public_root_renders_complete_tringers_career_portal_sections_and_aggregates_cross_tenant_eligible_jobs(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            // 4.1 Header & Navigation
            ->assertSee('Tringers')
            ->assertSee('Careers')
            ->assertSee('About Us')
            ->assertSee('Life at Tringers')
            ->assertSee('Our Locations')
            ->assertSee('Students')
            ->assertSee('Diversity & Inclusion')
            ->assertSee('Join Our Talent Network')
            // 4.2 Hero & Job Search
            ->assertSee('BUILD A BRIGHTER TOMORROW')
            ->assertSee('Grow Your Career With Tringers')
            ->assertSee('Search jobs by keyword, role or location')
            ->assertSee('Search Jobs')
            ->assertSee('Software Engineer')
            ->assertSee('Product Manager')
            ->assertSee('Data Analyst')
            ->assertSee('Remote')
            ->assertSee('India')
            // 4.3 Value Propositions
            ->assertSee('Meaningful Work')
            ->assertSee('Continuous Growth')
            ->assertSee('Inclusive Culture')
            ->assertSee('Global Opportunities')
            // 4.4 Explore Opportunities (6 categories)
            ->assertSee('Explore Opportunities')
            ->assertSee('Engineering')
            ->assertSee('Product & Design')
            ->assertSee('Data & Analytics')
            ->assertSee('Consulting')
            ->assertSee('Sales & Marketing')
            ->assertSee('Corporate Functions')
            // 4.5 Featured Jobs (cross-tenant published jobs)
            ->assertSee('Featured Jobs')
            ->assertSee('Explore Approved Open Positions')
            ->assertSee('Senior AI Platform Architect')
            ->assertSee('Lead Clinical Systems Engineer')
            // 4.6 Life at Tringers
            ->assertSee('LIFE AT TRINGERS')
            ->assertSee('A Place to Belong, Grow and Make an Impact')
            ->assertSee('Explore Life at Tringers')
            // Unapproved illustrative mockup figures must NOT be rendered publicly before verification
            ->assertDontSee('3,000+')
            // 4.8 Final CTA Banner
            ->assertSee('YOUR NEXT OPPORTUNITY AWAITS')
            ->assertSee('Let’s Build What’s Next')
            ->assertSee('View All Jobs')
            // 4.9 Footer & Legal links
            ->assertSee('People. Ideas. Impact.')
            ->assertSee('Quick Links')
            ->assertSee('Useful Links')
            ->assertSee('Privacy Policy')
            ->assertSee('Terms of Use')
            ->assertSee('Cookie Policy');

        // Verify legal pages and cookie consent endpoint work
        $this->get('/legal/privacy-policy')->assertOk()->assertSee('Candidate & Career Portal Privacy Policy');
        $this->get('/legal/terms-of-use')->assertOk()->assertSee('Career Portal Terms of Use');
        $this->get('/legal/cookie-policy')->assertOk()->assertSee('Cookie & Consent Governance Policy');

        $this->post('/careers/cookie-consent', ['consent_choice' => 'accepted'])
            ->assertRedirect()
            ->assertCookie('tringers_cookie_consent', 'accepted');
    }

    public function test_search_filters_popular_chips_and_category_filtering_work_end_to_end(): void
    {
        // Keyword search matches Acme job and excludes Globex job
        $this->get('/?q=Architect')
            ->assertOk()
            ->assertSee('Senior AI Platform Architect')
            ->assertDontSee('Lead Clinical Systems Engineer');

        // Category filter matches Engineering jobs
        $this->get('/?category=engineering')
            ->assertOk()
            ->assertSee('Senior AI Platform Architect');

        // Work mode filter
        $this->get('/?work_mode=HYBRID')
            ->assertOk()
            ->assertSee('Senior AI Platform Architect');

        // No results graceful empty state
        $this->get('/?q=NonExistentQuantumRole99999')
            ->assertOk()
            ->assertSee('No Matching Open Positions Found');
    }

    public function test_tenant_career_portal_isolation_precedence_and_immutable_slug(): void
    {
        $acme = Tenant::where('slug', 'acme-demo')->firstOrFail();
        $superAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();

        // Tenant portal displays only Acme jobs even if query string attempts to switch tenant
        $this->get('/acme-demo?tenant=globex-demo')
            ->assertOk()
            ->assertSee('Explore Open Positions at '.$acme->name)
            ->assertSee('Senior AI Platform Architect')
            ->assertDontSee('Lead Clinical Systems Engineer');

        // Save and publish a tenant override for Acme
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.draft.save'), [
            'tenant_id' => $acme->id,
            'active_section' => 'hero_search',
            'payload' => [
                'hero_search' => [
                    'headline' => 'Build Enterprise AI at Acme Demo',
                ],
            ],
            'change_summary' => 'Customize Acme tenant hero headline',
        ])->assertRedirect();

        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.publish'), [
            'tenant_id' => $acme->id,
            'change_summary' => 'Publish Acme tenant hero headline override',
        ])->assertRedirect();

        // Acme tenant portal reflects the override, while global root portal remains unchanged
        $this->get('/acme-demo')
            ->assertOk()
            ->assertSee('Build Enterprise AI at Acme Demo');

        $this->get('/')
            ->assertOk()
            ->assertSee('Grow Your Career With Tringers')
            ->assertDontSee('Build Enterprise AI at Acme Demo');

        // Tenant slug remains immutable
        $this->assertSame('acme-demo', $acme->fresh()->slug);
    }

    public function test_platform_admin_navigation_and_rbac_permission_matrix(): void
    {
        $superAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();
        $tenantAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();

        // 1. Tenant-scoped users cannot access Platform Administration -> Career Portal Configuration
        $this->actingAs($tenantAdmin)
            ->get(route('platform-admin.career-portal.index'))
            ->assertForbidden();

        $this->actingAs($recruiter)
            ->get(route('platform-admin.career-portal.index'))
            ->assertForbidden();

        // 2. Platform Super Admin sees menu item and can access workspace
        $this->actingAs($superAdmin)
            ->get(route('platform-admin.index'))
            ->assertOk()
            ->assertSee('Career Portal Configuration');

        $this->actingAs($superAdmin)
            ->get(route('platform-admin.career-portal.index'))
            ->assertOk()
            ->assertSee('Career Portal Configuration');

        // 3. Ordinary platform_admin without explicit career_portal permission cannot access or edit
        $ordinaryPlatformAdmin = new User;
        $ordinaryPlatformAdmin->forceFill([
            'name' => 'Ordinary Platform Admin',
            'email' => 'ordinary.platform@tringers.test',
            'password' => 'AlphaDemo#2026!',
            'status' => 'ACTIVE',
            'is_platform_superadmin' => false,
            'platform_role' => 'platform_admin',
            'mfa_enabled' => true,
        ])->save();

        $this->actingAs($ordinaryPlatformAdmin)
            ->get(route('platform-admin.index'))
            ->assertOk()
            ->assertDontSee(route('platform-admin.career-portal.index'));

        $this->actingAs($ordinaryPlatformAdmin)
            ->get(route('platform-admin.career-portal.index'))
            ->assertForbidden();

        // 4. Platform Portal Editor can save drafts, validate, and preview, but CANNOT publish or rollback
        $portalEditor = new User;
        $portalEditor->forceFill([
            'name' => 'Portal Configuration Editor',
            'email' => 'portal.editor@tringers.test',
            'password' => 'AlphaDemo#2026!',
            'status' => 'ACTIVE',
            'is_platform_superadmin' => false,
            'platform_role' => 'platform_portal_editor',
            'mfa_enabled' => true,
        ])->save();

        $this->actingAs($portalEditor)
            ->get(route('platform-admin.career-portal.index'))
            ->assertOk();

        $this->actingAs($portalEditor)
            ->post(route('platform-admin.career-portal.draft.save'), [
                'active_section' => 'hero_search',
                'payload' => [
                    'hero_search' => [
                        'headline' => 'Editor Draft Headline',
                    ],
                ],
            ])->assertRedirect();

        $this->actingAs($portalEditor)
            ->post(route('platform-admin.career-portal.publish'), [
                'change_summary' => 'Editor attempting unauthorized publish',
            ])->assertForbidden();
    }

    public function test_draft_isolation_preview_security_atomic_publish_versioning_and_rollback(): void
    {
        $superAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();

        // Ensure baseline global configuration exists
        $this->actingAs($superAdmin)->get(route('platform-admin.career-portal.index'))->assertOk();
        $config = CareerPortalConfiguration::where('scope', CareerPortalConfiguration::SCOPE_GLOBAL)->firstOrFail();
        $initialDraftVersion = $config->draft_version;

        // 1. Save a draft change
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.draft.save'), [
            'active_section' => 'hero_search',
            'expected_draft_version' => $initialDraftVersion,
            'payload' => [
                'hero_search' => [
                    'headline' => 'Unpublished Future Career Headline',
                ],
            ],
            'change_summary' => 'Update hero headline in draft',
        ])->assertRedirect();

        $config->refresh();
        $this->assertSame($initialDraftVersion + 1, $config->draft_version);

        // 2. Public portal MUST NOT show unpublished draft changes
        $this->get('/')
            ->assertOk()
            ->assertSee('Grow Your Career With Tringers')
            ->assertDontSee('Unpublished Future Career Headline');

        // 3. Preview endpoint generates a signed preview URL that shows draft changes with noindex & no-store headers
        $previewResponse = $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.preview'));
        $previewResponse->assertRedirect();
        $previewUrl = $previewResponse->headers->get('Location');
        $this->assertNotEmpty($previewUrl);

        $renderedPreview = $this->actingAs($superAdmin)->get($previewUrl);
        $renderedPreview->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('DRAFT PREVIEW MODE (NON-PUBLIC)')
            ->assertSee('Unpublished Future Career Headline');

        // Unauthenticated visitor cannot use the preview URL
        auth()->logout();
        $this->get($previewUrl)->assertForbidden();

        // 4. Explicitly publish the validated draft
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.publish'), [
            'expected_draft_version' => $config->draft_version,
            'change_summary' => 'Publish new hero headline v2',
        ])->assertRedirect();

        $config->refresh();
        $this->assertSame(2, $config->published_version_number);

        // Public portal now reflects the published configuration
        $this->get('/')
            ->assertOk()
            ->assertSee('Unpublished Future Career Headline');

        // 5. Verify version records and publication events are append-only / immutable
        $versionRecord = CareerPortalConfigurationVersion::where('configuration_id', $config->id)
            ->where('version_number', 2)
            ->firstOrFail();

        try {
            $versionRecord->change_summary = 'Tampered history';
            $versionRecord->save();
            $this->fail('Mutating an existing CareerPortalConfigurationVersion must throw LogicException.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('CAREER_PORTAL_VERSION_IMMUTABLE', $e->getMessage());
        }

        $pubEvent = CareerPortalPublicationEvent::where('configuration_id', $config->id)->firstOrFail();
        try {
            $pubEvent->delete();
            $this->fail('Deleting a CareerPortalPublicationEvent must throw LogicException.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('CAREER_PORTAL_PUBLICATION_EVENT_IMMUTABLE', $e->getMessage());
        }

        // 6. Rollback to version 1 creates new version v3 and restores original headline
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.rollback', ['versionNumber' => 1]), [
            'reason' => 'Rollback to initial baseline v1 after verification',
        ])->assertRedirect();

        $config->refresh();
        $this->assertSame(3, $config->published_version_number);

        $this->get('/')
            ->assertOk()
            ->assertSee('Grow Your Career With Tringers')
            ->assertDontSee('Unpublished Future Career Headline');
    }

    public function test_optimistic_concurrency_validation_wcag_contrast_xss_sanitization_and_content_governance(): void
    {
        $superAdmin = User::where('email', 'platform.superadmin@tringers.test')->firstOrFail();
        $this->actingAs($superAdmin)->get(route('platform-admin.career-portal.index'))->assertOk();
        $config = CareerPortalConfiguration::where('scope', CareerPortalConfiguration::SCOPE_GLOBAL)->firstOrFail();

        // 1. Stale draft version is rejected by optimistic concurrency lock
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.draft.save'), [
            'active_section' => 'hero_search',
            'expected_draft_version' => 9999,
            'payload' => [
                'hero_search' => ['headline' => 'Stale Concurrent Write'],
            ],
        ])->assertSessionHasErrors('concurrency');

        // 2. XSS script tags are stripped from text inputs and unsafe javascript: URLs are blocked by validation
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.draft.save'), [
            'active_section' => 'header_navigation',
            'payload' => [
                'header_navigation' => [
                    'cta_label' => '<script>alert("xss")</script>Join Talent Network',
                    'cta_url' => 'javascript:alert(1)',
                ],
            ],
        ])->assertRedirect();

        $config->refresh();
        $this->assertSame('Join Talent Network', $config->draft_payload['header_navigation']['cta_label']);
        $this->assertSame('INVALID', $config->validation_status);
        $this->assertArrayHasKey('header_navigation.cta_url', $config->validation_errors);

        // Invalid draft cannot be published
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.publish'), [
            'change_summary' => 'Attempt to publish invalid URL draft',
        ])->assertSessionHasErrors('header_navigation.cta_url');

        // Reset draft to Tringers Default Theme restores valid state
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.draft.reset'))->assertRedirect();
        $config->refresh();
        $this->assertSame('VALID', $config->validation_status);

        // 3. Low-contrast color fails WCAG 2.2 AA contrast check and blocks publication
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.draft.save'), [
            'active_section' => 'brand_theme',
            'payload' => [
                'brand_theme' => [
                    'body_color' => '#E2E8F0', // 1.26:1 on #FFFFFF
                ],
            ],
        ])->assertRedirect();

        $config->refresh();
        $this->assertSame('INVALID', $config->validation_status);
        $this->assertArrayHasKey('brand_theme.body_color', $config->validation_errors);

        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.draft.reset'))->assertRedirect();

        // 4. Approved + Consent-Confirmed + Published Employee Story renders on public portal
        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.draft.save'), [
            'active_section' => 'employee_stories',
            'payload' => [
                'employee_stories' => [
                    'stories' => [
                        [
                            'id' => 'story-approved-1',
                            'name' => 'Aarav Mehta',
                            'role' => 'Staff Platform Engineer',
                            'location' => 'Bengaluru',
                            'quote' => 'Solving distributed systems challenges with a supportive global team has accelerated my growth.',
                            'approved' => true,
                            'consent_confirmed' => true,
                            'published' => true,
                            'order' => 1,
                        ],
                    ],
                ],
            ],
        ])->assertRedirect();

        $this->actingAs($superAdmin)->post(route('platform-admin.career-portal.publish'), [
            'change_summary' => 'Publish verified employee story with consent',
        ])->assertRedirect();

        $this->get('/')
            ->assertOk()
            ->assertSee('Aarav Mehta')
            ->assertSee('Solving distributed systems challenges with a supportive global team has accelerated my growth.');
    }

    public function test_user_facing_journeys_eliminate_development_mode_demo_and_alpha_scaffolding_artifacts(): void
    {
        $prohibitedStrings = [
            '<span class="badge bg-warning text-dark ms-1">Alpha</span>',
            'Unified Recruitment AI Platform (Alpha)',
            '(DEMO)',
            '(Demo)',
            '(Globex Demo)',
            '(Demo Candidate)',
            'Development Demo Personas',
            'DEMO ONLY',
            'VALIDATION_ERROR',
            'MICROSOFT_ENTRA_CLIENT_ID',
            'Alpha Sandbox',
            'DeterministicFake',
            'SSN 123-45-6789',
        ];

        $publicUrls = [
            '/',
            '/careers',
            '/acme-demo',
            '/acme-demo/jobs/senior-ai-platform-architect-job-2026-0101',
            '/login',
            '/legal/privacy-policy',
            '/legal/terms-of-use',
            '/legal/cookie-policy',
        ];

        foreach ($publicUrls as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            foreach ($prohibitedStrings as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $html,
                    "Public route [{$url}] must not expose development/demo indicator [{$needle}]."
                );
            }
        }

        $tenantAdmin = User::where('email', 'admin@acme-demo.test')->firstOrFail();
        $authenticatedUrls = [
            '/dashboard',
            '/organization',
            '/organization/setup',
            '/requisitions',
            '/jobs',
            '/candidates',
            '/applications',
            '/evaluations',
            '/offers',
            '/hr-handover',
            '/ai',
            '/integrations/api',
            '/integrations/dynamics',
            '/analytics',
        ];

        foreach ($authenticatedUrls as $url) {
            $html = (string) $this->actingAs($tenantAdmin)->get($url)->assertOk()->getContent();
            foreach ($prohibitedStrings as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $html,
                    "Authenticated workspace route [{$url}] must not expose development/demo indicator [{$needle}]."
                );
            }
        }
    }
}
