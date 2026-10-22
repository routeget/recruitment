<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Interview;
use App\Models\User;
use Database\Seeders\AlphaSafeBootstrapSeeder;
use Tests\TestCase;

class ScreeningInterviewDecisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AlphaSafeBootstrapSeeder::class);
    }

    public function test_screening_assessment_interview_scorecard_and_hiring_decision_workflow(): void
    {
        $recruiter = User::where('email', 'recruiter@acme-demo.test')->firstOrFail();
        $manager = User::where('email', 'manager@acme-demo.test')->firstOrFail();
        $app = Application::withoutTenantScope()->where('application_number', 'APP-2026-1001')->firstOrFail();

        // 1. Record screening evaluation
        $this->actingAs($recruiter)->post('/evaluations/screening', [
            'application_id' => $app->id,
            'overall_score' => 95.0,
            'recommendation' => 'STRONG_YES',
            'notes' => 'Strong distributed systems and security track record.',
        ])->assertRedirect();

        $this->assertSame('95.00', (string) $app->fresh()->screening_score);

        // 2. Record assessment
        $this->actingAs($recruiter)->post('/evaluations/assessments', [
            'application_id' => $app->id,
            'title' => 'System Design & Security Assessment',
            'assessment_type' => 'TECHNICAL',
            'score' => 96.5,
        ])->assertRedirect();

        // 3. Schedule interview
        $this->actingAs($recruiter)->post('/evaluations/interviews', [
            'application_id' => $app->id,
            'stage_name' => 'Final Architecture Panel',
            'interview_mode' => 'PANEL',
            'scheduled_start_at' => now()->addDay()->toDateTimeString(),
            'scheduled_end_at' => now()->addDay()->addHour()->toDateTimeString(),
        ])->assertRedirect();

        $interview = Interview::withoutTenantScope()->where('application_id', $app->id)->firstOrFail();

        // 4. Hiring manager submits scorecard
        $this->actingAs($manager)->post('/evaluations/scorecards', [
            'interview_id' => $interview->id,
            'overall_rating' => 5,
            'recommendation' => 'STRONG_HIRE',
            'strengths_evidence' => 'Deep understanding of transactional outbox and tenant isolation.',
        ])->assertRedirect();

        $this->assertSame('COMPLETED', $interview->fresh()->status);

        // 5. Recruiter WITHOUT decisions.manage permission cannot record final hiring decision
        $this->actingAs($recruiter)->post('/evaluations/decisions', [
            'application_id' => $app->id,
            'decision' => 'APPROVE_FOR_OFFER',
            'rationale' => 'Recruiter trying to bypass hiring manager decision.',
        ])->assertStatus(403);

        // 6. Hiring manager WITH decisions.manage records APPROVE_FOR_OFFER decision
        $this->actingAs($manager)->post('/evaluations/decisions', [
            'application_id' => $app->id,
            'decision' => 'APPROVE_FOR_OFFER',
            'rationale' => 'Approved for offer based on 95 screening score and 5/5 interview scorecard.',
        ])->assertRedirect();

        $this->assertSame(Application::STAGE_OFFER_IN_PROGRESS, $app->fresh()->current_stage);
    }
}
