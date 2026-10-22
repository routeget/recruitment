<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\JobPosting;
use App\Models\Requisition;
use App\Models\User;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CareerPortalAndApplicationLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);
    }

    public function test_public_career_portal_shows_published_jobs_with_json_ld_and_hides_draft_jobs(): void
    {
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $req = Requisition::withoutTenantScope()->where('requisition_number', 'REQ-2026-0101')->firstOrFail();

        // Create a DRAFT job posting
        $this->actingAs($recruiter)->post('/jobs', [
            'requisition_id' => $req->id,
            'title' => 'Unpublished Secret Draft Role',
            'work_mode' => 'REMOTE',
            'experience_level' => 'SENIOR',
            'summary' => 'Draft summary not for public portal.',
            'responsibilities' => 'Internal responsibilities.',
            'qualifications' => 'Internal qualifications.',
            'skills_required' => 'PHP, Laravel',
        ]);

        $draftJob = JobPosting::withoutTenantScope()->where('title', 'Unpublished Secret Draft Role')->firstOrFail();
        $this->assertSame(JobPosting::STATUS_DRAFT, $draftJob->status);

        // Public portal lists published job and hides draft job
        $portalResp = $this->get('/careers');
        $portalResp->assertStatus(200)
            ->assertSee('Senior AI Platform Architect')
            ->assertDontSee('Unpublished Secret Draft Role');

        // Direct access to draft job on public portal returns 404
        $this->get("/careers/acme-demo/jobs/{$draftJob->slug}")->assertStatus(404);

        // Published job detail renders Schema.org JobPosting JSON-LD
        $publishedJob = JobPosting::withoutTenantScope()->where('code', 'JOB-2026-0101')->firstOrFail();
        $detailResp = $this->get("/careers/acme-demo/jobs/{$publishedJob->slug}");
        $detailResp->assertStatus(200)
            ->assertSee('"@type": "JobPosting"', false)
            ->assertSee($publishedJob->title);

        // Sitemap & robots
        $this->get('/sitemap.xml')->assertStatus(200)->assertSee($publishedJob->slug);
        $this->get('/robots.txt')->assertStatus(200)->assertSee('Disallow: /hr-handover');
    }

    public function test_public_candidate_application_requires_consent_stores_private_document_and_prevents_duplicates(): void
    {
        Storage::fake('local');
        $publishedJob = JobPosting::withoutTenantScope()->where('code', 'JOB-2026-0101')->firstOrFail();

        // Missing consent is rejected
        $noConsentResp = $this->post("/careers/acme-demo/jobs/{$publishedJob->slug}/apply", [
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'email' => 'alex.rivera@example.test',
            'country_code' => 'US',
        ]);
        $noConsentResp->assertSessionHasErrors('consent_given');

        // Valid application with PDF resume
        $pdfFile = UploadedFile::fake()->createWithContent('alex_resume.pdf', "%PDF-1.4\nSynthetic Resume Content");
        $applyResp = $this->post("/careers/acme-demo/jobs/{$publishedJob->slug}/apply", [
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'email' => 'alex.rivera@example.test',
            'phone' => '+1-555-0123',
            'location_city' => 'Austin',
            'country_code' => 'US',
            'current_title' => 'Backend Engineer',
            'years_experience' => 5,
            'skills' => 'PHP, Laravel, MariaDB',
            'cover_note' => 'Applying for Senior AI Platform Architect.',
            'consent_given' => '1',
            'resume_file' => $pdfFile,
        ]);

        $applyResp->assertRedirect(route('careers.show', ['acme-demo', $publishedJob->slug]));

        $candidate = Candidate::withoutTenantScope()->where('email', 'alex.rivera@example.test')->firstOrFail();
        $this->assertTrue($candidate->consent_given);

        $doc = CandidateDocument::withoutTenantScope()->where('candidate_id', $candidate->id)->firstOrFail();
        $this->assertNotEmpty($doc->sha256_checksum);
        Storage::disk('local')->assertExists($doc->storage_path);

        // Duplicate application to the same job is rejected
        $dupResp = $this->post("/careers/acme-demo/jobs/{$publishedJob->slug}/apply", [
            'first_name' => 'Alex',
            'last_name' => 'Rivera',
            'email' => 'alex.rivera@example.test',
            'country_code' => 'US',
            'consent_given' => '1',
        ]);
        $dupResp->assertSessionHasErrors('email');
    }

    public function test_executable_upload_is_rejected_and_cross_tenant_document_download_is_blocked(): void
    {
        Storage::fake('local');
        $acmeRecruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $globexAdmin = User::where('email', 'admin@globex-demo.test')->firstOrFail();
        $acmeCandidate = Candidate::withoutTenantScope()->where('email', 'jordan.taylor@candidate-demo.test')->firstOrFail();

        // Attempt to upload an executable .php file disguised as document
        $badFile = UploadedFile::fake()->create('exploit.php', 10, 'application/x-php');
        $badResp = $this->actingAs($acmeRecruiter)->postJson("/candidates/{$acmeCandidate->id}/documents", [
            'document_type' => 'RESUME',
            'document' => $badFile,
        ]);
        $badResp->assertStatus(422);

        // Upload valid PDF document in Tenant A
        $validPdf = UploadedFile::fake()->createWithContent('jordan_cv.pdf', "%PDF-1.4\nJordan Taylor CV");
        $this->actingAs($acmeRecruiter)->post("/candidates/{$acmeCandidate->id}/documents", [
            'document_type' => 'RESUME',
            'document' => $validPdf,
        ])->assertRedirect(route('candidates.show', $acmeCandidate->id));

        $doc = CandidateDocument::withoutTenantScope()->where('candidate_id', $acmeCandidate->id)->firstOrFail();

        // Authorized Tenant A user can download
        $this->actingAs($acmeRecruiter)->get("/documents/{$doc->id}/download")->assertStatus(200);

        // Tenant B admin attempting to download Tenant A candidate document receives 403 TENANT_SCOPE_VIOLATION
        $this->actingAs($globexAdmin)->get("/documents/{$doc->id}/download")->assertStatus(403);
    }

    public function test_application_stage_transitions_enforce_state_machine_and_tenant_isolation(): void
    {
        $acmeRecruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $globexAdmin = User::where('email', 'admin@globex-demo.test')->firstOrFail();
        $app = Application::withoutTenantScope()->where('application_number', 'APP-2026-1001')->firstOrFail();
        $this->assertSame(Application::STAGE_DECISION_PENDING, $app->current_stage);

        // Illegal backward jump from DECISION_PENDING to RECEIVED is rejected
        $this->actingAs($acmeRecruiter)->postJson("/applications/{$app->id}/transition", [
            'target_stage' => Application::STAGE_RECEIVED,
        ])->assertStatus(422);

        // Valid transition from DECISION_PENDING to OFFER_IN_PROGRESS succeeds
        $this->actingAs($acmeRecruiter)->post("/applications/{$app->id}/transition", [
            'target_stage' => Application::STAGE_OFFER_IN_PROGRESS,
            'notes' => 'Moving candidate to offer preparation.',
        ])->assertRedirect(route('applications.show', $app->id));

        $this->assertSame(Application::STAGE_OFFER_IN_PROGRESS, $app->fresh()->current_stage);

        // Tenant B user cannot view or transition Tenant A application
        $this->actingAs($globexAdmin)->get("/applications/{$app->id}")->assertStatus(403);
    }
}
