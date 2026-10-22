<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_evaluations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('evaluator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('evaluation_source', 40)->default('HUMAN');
            $table->json('criteria_breakdown');
            $table->decimal('overall_score', 5, 2);
            $table->string('recommendation', 32)->default('YES');
            $table->json('missing_evidence_flags')->nullable();
            $table->boolean('human_reviewed')->default(true);
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('assessment_type', 40)->default('TECHNICAL');
            $table->string('vendor_adapter', 48)->default('INTERNAL');
            $table->string('status', 32)->default('COMPLETED');
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->default(100.00);
            $table->text('accommodations_notes')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('stage_name', 120);
            $table->string('interview_mode', 32)->default('VIDEO');
            $table->timestamp('scheduled_start_at');
            $table->timestamp('scheduled_end_at');
            $table->string('location_or_link', 255)->nullable();
            $table->foreignId('interviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('organizer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('SCHEDULED');
            $table->text('interview_pack_notes')->nullable();
            $table->timestamp('feedback_deadline_at')->nullable();
            $table->timestamps();
        });

        Schema::create('interview_scorecards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('interviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->json('competency_ratings');
            $table->unsignedTinyInteger('overall_rating');
            $table->string('recommendation', 32);
            $table->text('strengths_evidence');
            $table->text('concerns_evidence')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            $table->unique(['interview_id', 'interviewer_user_id']);
        });

        Schema::create('hiring_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('requisition_id')->constrained('requisitions')->restrictOnDelete();
            $table->string('decision', 40);
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('rationale');
            $table->json('evidence_summary');
            $table->timestamp('decided_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_decisions');
        Schema::dropIfExists('interview_scorecards');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('screening_evaluations');
    }
};
