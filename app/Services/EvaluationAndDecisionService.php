<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationStageEvent;
use App\Models\Assessment;
use App\Models\HiringDecision;
use App\Models\Interview;
use App\Models\InterviewScorecard;
use App\Models\ScreeningEvaluation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EvaluationAndDecisionService
{
    public function __construct(
        private readonly TenantContextService $context,
        private readonly AuditService $audit
    ) {}

    public function recordScreeningEvaluation(Application $app, array $data, User $actor): ScreeningEvaluation
    {
        $this->context->assertModelBelongsToActiveTenant($app, 'Application');

        return DB::transaction(function () use ($app, $data, $actor) {
            $eval = ScreeningEvaluation::create([
                'tenant_id' => $app->tenant_id,
                'application_id' => $app->id,
                'evaluator_user_id' => $actor->id,
                'evaluation_source' => $data['evaluation_source'] ?? 'HUMAN',
                'criteria_breakdown' => $data['criteria_breakdown'] ?? [
                    'core_technical_skills' => (float) ($data['overall_score'] ?? 85),
                    'domain_relevance' => (float) ($data['overall_score'] ?? 85),
                ],
                'overall_score' => (float) $data['overall_score'],
                'recommendation' => $data['recommendation'],
                'missing_evidence_flags' => $data['missing_evidence_flags'] ?? [],
                'human_reviewed' => true,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $app->update([
                'screening_score' => $eval->overall_score,
                'eligibility_verified' => true,
            ]);

            $this->audit->record(
                module: 'screening',
                action: 'screening_evaluation_recorded',
                outcome: 'SUCCESS',
                entityType: 'ScreeningEvaluation',
                entityId: (string) $eval->id,
                metadata: [
                    'application_id' => $app->id,
                    'overall_score' => (float) $eval->overall_score,
                    'recommendation' => $eval->recommendation,
                ]
            );

            return $eval;
        });
    }

    public function recordAssessment(Application $app, array $data, User $actor): Assessment
    {
        $this->context->assertModelBelongsToActiveTenant($app, 'Application');

        return DB::transaction(function () use ($app, $data, $actor) {
            $assessment = Assessment::create([
                'tenant_id' => $app->tenant_id,
                'application_id' => $app->id,
                'title' => $data['title'],
                'assessment_type' => $data['assessment_type'] ?? 'TECHNICAL',
                'vendor_adapter' => 'INTERNAL',
                'status' => isset($data['score']) ? 'COMPLETED' : 'INVITED',
                'score' => $data['score'] ?? null,
                'max_score' => $data['max_score'] ?? 100.00,
                'accommodations_notes' => $data['accommodations_notes'] ?? null,
                'valid_until' => now()->addDays(14),
                'completed_at' => isset($data['score']) ? now() : null,
                'recorded_by_user_id' => $actor->id,
            ]);

            $this->audit->record(
                module: 'screening',
                action: 'assessment_recorded',
                outcome: 'SUCCESS',
                entityType: 'Assessment',
                entityId: (string) $assessment->id,
                metadata: ['application_id' => $app->id, 'status' => $assessment->status]
            );

            return $assessment;
        });
    }

    public function scheduleInterview(Application $app, array $data, User $actor): Interview
    {
        $this->context->assertModelBelongsToActiveTenant($app, 'Application');

        return DB::transaction(function () use ($app, $data, $actor) {
            $interview = Interview::create([
                'tenant_id' => $app->tenant_id,
                'application_id' => $app->id,
                'stage_name' => $data['stage_name'],
                'interview_mode' => $data['interview_mode'] ?? 'VIDEO',
                'scheduled_start_at' => $data['scheduled_start_at'],
                'scheduled_end_at' => $data['scheduled_end_at'],
                'location_or_link' => $data['location_or_link'] ?? 'https://meet.tringers.test/interview-room',
                'interviewer_user_id' => $data['interviewer_user_id'] ?? $actor->id,
                'organizer_user_id' => $actor->id,
                'status' => 'SCHEDULED',
                'interview_pack_notes' => $data['interview_pack_notes'] ?? null,
                'feedback_deadline_at' => now()->addDays(3),
            ]);

            if (in_array($app->current_stage, [Application::STAGE_RECEIVED, Application::STAGE_SCREENING, Application::STAGE_SHORTLISTED, Application::STAGE_ASSESSMENT], true)) {
                $fromStage = $app->current_stage;
                $app->update([
                    'current_stage' => Application::STAGE_INTERVIEW,
                    'candidate_visible_status' => 'Interview Stage',
                ]);
                ApplicationStageEvent::create([
                    'tenant_id' => $app->tenant_id,
                    'application_id' => $app->id,
                    'from_stage' => $fromStage,
                    'to_stage' => Application::STAGE_INTERVIEW,
                    'actor_user_id' => $actor->id,
                    'notes' => 'Scheduled interview: '.$interview->stage_name,
                    'created_at' => now(),
                ]);
            }

            $this->audit->record(
                module: 'interviews',
                action: 'interview_scheduled',
                outcome: 'SUCCESS',
                entityType: 'Interview',
                entityId: (string) $interview->id,
                metadata: ['application_id' => $app->id, 'stage_name' => $interview->stage_name]
            );

            return $interview;
        });
    }

    public function submitScorecard(Interview $interview, array $data, User $interviewer): InterviewScorecard
    {
        $this->context->assertModelBelongsToActiveTenant($interview, 'Interview');

        return DB::transaction(function () use ($interview, $data, $interviewer) {
            $scorecard = InterviewScorecard::updateOrCreate(
                [
                    'interview_id' => $interview->id,
                    'interviewer_user_id' => $interviewer->id,
                ],
                [
                    'tenant_id' => $interview->tenant_id,
                    'application_id' => $interview->application_id,
                    'competency_ratings' => $data['competency_ratings'] ?? [
                        'technical_depth' => (int) $data['overall_rating'],
                        'system_design' => (int) $data['overall_rating'],
                        'communication' => (int) $data['overall_rating'],
                    ],
                    'overall_rating' => (int) $data['overall_rating'],
                    'recommendation' => $data['recommendation'],
                    'strengths_evidence' => $data['strengths_evidence'],
                    'concerns_evidence' => $data['concerns_evidence'] ?? null,
                    'submitted_at' => now(),
                ]
            );

            $interview->update(['status' => 'COMPLETED']);

            $this->audit->record(
                module: 'interviews',
                action: 'interview_scorecard_submitted',
                outcome: 'SUCCESS',
                entityType: 'InterviewScorecard',
                entityId: (string) $scorecard->id,
                metadata: [
                    'interview_id' => $interview->id,
                    'overall_rating' => $scorecard->overall_rating,
                    'recommendation' => $scorecard->recommendation,
                ]
            );

            return $scorecard;
        });
    }

    public function recordHiringDecision(Application $app, array $data, User $decisionMaker): HiringDecision
    {
        $this->context->assertModelBelongsToActiveTenant($app, 'Application');

        if (! $decisionMaker->hasPermission('decisions.manage', $app->tenant_id)) {
            throw new AccessDeniedHttpException('PERMISSION_DENIED: User lacks decisions.manage permission.');
        }

        return DB::transaction(function () use ($app, $data, $decisionMaker) {
            $lockedApp = Application::lockForUpdate()->findOrFail($app->id);

            if (in_array($lockedApp->current_stage, [Application::STAGE_REJECTED, Application::STAGE_WITHDRAWN], true)) {
                throw ValidationException::withMessages([
                    'application_id' => ['INVALID_STATE_TRANSITION: Cannot record hiring decision on a rejected or withdrawn application.'],
                ]);
            }

            $scorecards = InterviewScorecard::where('application_id', $lockedApp->id)->get();
            $assessments = Assessment::where('application_id', $lockedApp->id)->get();

            $decisionRecord = HiringDecision::create([
                'tenant_id' => $lockedApp->tenant_id,
                'application_id' => $lockedApp->id,
                'requisition_id' => $lockedApp->requisition_id,
                'decision' => $data['decision'],
                'decided_by_user_id' => $decisionMaker->id,
                'rationale' => $data['rationale'],
                'evidence_summary' => [
                    'screening_score' => (float) $lockedApp->screening_score,
                    'completed_scorecards_count' => $scorecards->count(),
                    'average_interview_rating' => round((float) $scorecards->avg('overall_rating'), 2),
                    'assessments_count' => $assessments->count(),
                ],
                'decided_at' => now(),
            ]);

            $fromStage = $lockedApp->current_stage;
            if ($data['decision'] === 'APPROVE_FOR_OFFER') {
                $lockedApp->current_stage = Application::STAGE_OFFER_IN_PROGRESS;
                $lockedApp->candidate_visible_status = 'Offer Preparation in Progress';
            } elseif ($data['decision'] === 'REJECT') {
                $lockedApp->current_stage = Application::STAGE_REJECTED;
                $lockedApp->candidate_visible_status = 'Application Closed — Not Selected';
                $lockedApp->rejected_at = now();
                $lockedApp->rejection_reason = $data['rationale'];
            } else {
                $lockedApp->current_stage = Application::STAGE_ON_HOLD;
                $lockedApp->candidate_visible_status = 'Application On Hold';
            }
            $lockedApp->save();

            ApplicationStageEvent::create([
                'tenant_id' => $lockedApp->tenant_id,
                'application_id' => $lockedApp->id,
                'from_stage' => $fromStage,
                'to_stage' => $lockedApp->current_stage,
                'actor_user_id' => $decisionMaker->id,
                'notes' => "Hiring decision {$data['decision']}: {$data['rationale']}",
                'created_at' => now(),
            ]);

            $this->audit->record(
                module: 'decisions',
                action: 'hiring_decision_recorded',
                outcome: 'SUCCESS',
                entityType: 'HiringDecision',
                entityId: (string) $decisionRecord->id,
                metadata: [
                    'application_id' => $lockedApp->id,
                    'decision' => $decisionRecord->decision,
                    'resulting_stage' => $lockedApp->current_stage,
                ]
            );

            return $decisionRecord;
        });
    }
}
