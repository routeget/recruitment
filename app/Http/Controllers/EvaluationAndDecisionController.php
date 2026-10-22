<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\HiringDecision;
use App\Models\Interview;
use App\Models\InterviewScorecard;
use App\Models\ScreeningEvaluation;
use App\Services\EvaluationAndDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class EvaluationAndDecisionController extends Controller
{
    public function index(Request $request): View
    {
        $applications = Application::with(['candidate', 'jobPosting'])->orderByDesc('id')->get();
        $selectedAppId = $request->integer('application_id') ?: $applications->first()?->id;

        return view('evaluations.index', [
            'applications' => $applications,
            'selectedAppId' => $selectedAppId,
            'screeningEvaluations' => ScreeningEvaluation::with(['application.candidate', 'evaluator'])->orderByDesc('id')->limit(15)->get(),
            'assessments' => Assessment::with('application.candidate')->orderByDesc('id')->limit(15)->get(),
            'interviews' => Interview::with(['application.candidate', 'interviewer', 'scorecards'])->orderByDesc('id')->limit(15)->get(),
            'scorecards' => InterviewScorecard::with(['interview.application.candidate', 'interviewer'])->orderByDesc('id')->limit(15)->get(),
            'decisions' => HiringDecision::with(['application.candidate', 'decisionMaker'])->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    public function storeScreening(Request $request, EvaluationAndDecisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'overall_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'recommendation' => ['required', 'in:STRONG_YES,YES,NEUTRAL,NO'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $app = Application::withoutTenantScope()->findOrFail((int) $validated['application_id']);
        $eval = $service->recordScreeningEvaluation($app, $validated, Auth::user());

        return redirect()->route('evaluations.index', ['application_id' => $app->id])
            ->with('status', "Recorded screening evaluation ({$eval->overall_score}/100 — {$eval->recommendation}) for {$app->application_number}.");
    }

    public function storeAssessment(Request $request, EvaluationAndDecisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'title' => ['required', 'string', 'max:160'],
            'assessment_type' => ['required', 'in:TECHNICAL,CASE_STUDY,WORK_SAMPLE,CODING'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'accommodations_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $app = Application::withoutTenantScope()->findOrFail((int) $validated['application_id']);
        $assessment = $service->recordAssessment($app, $validated, Auth::user());

        return redirect()->route('evaluations.index', ['application_id' => $app->id])
            ->with('status', "Recorded assessment '{$assessment->title}' ({$assessment->status}).");
    }

    public function storeInterview(Request $request, EvaluationAndDecisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'stage_name' => ['required', 'string', 'max:120'],
            'interview_mode' => ['required', 'in:VIDEO,ONSITE,PHONE,PANEL'],
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at'],
            'location_or_link' => ['nullable', 'string', 'max:255'],
            'interview_pack_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $app = Application::withoutTenantScope()->findOrFail((int) $validated['application_id']);
        $interview = $service->scheduleInterview($app, $validated, Auth::user());

        return redirect()->route('evaluations.index', ['application_id' => $app->id])
            ->with('status', "Scheduled interview '{$interview->stage_name}' for {$app->application_number}.");
    }

    public function storeScorecard(Request $request, EvaluationAndDecisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'interview_id' => ['required', 'integer', 'exists:interviews,id'],
            'overall_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'recommendation' => ['required', 'in:STRONG_HIRE,HIRE,NO_HIRE,STRONG_NO_HIRE'],
            'strengths_evidence' => ['required', 'string', 'min:5', 'max:1500'],
            'concerns_evidence' => ['nullable', 'string', 'max:1500'],
        ]);

        $interview = Interview::withoutTenantScope()->findOrFail((int) $validated['interview_id']);
        $scorecard = $service->submitScorecard($interview, $validated, Auth::user());

        return redirect()->route('evaluations.index', ['application_id' => $interview->application_id])
            ->with('status', "Submitted interview scorecard ({$scorecard->overall_rating}/5 — {$scorecard->recommendation}).");
    }

    public function storeDecision(Request $request, EvaluationAndDecisionService $service): RedirectResponse
    {
        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:applications,id'],
            'decision' => ['required', 'in:APPROVE_FOR_OFFER,REJECT,HOLD'],
            'rationale' => ['required', 'string', 'min:10', 'max:1500'],
        ]);

        $app = Application::withoutTenantScope()->findOrFail((int) $validated['application_id']);
        $decision = $service->recordHiringDecision($app, $validated, Auth::user());

        return redirect()->route('evaluations.index', ['application_id' => $app->id])
            ->with('status', "Recorded immutable hiring decision {$decision->decision} for {$app->application_number}.");
    }
}
