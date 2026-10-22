<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table): void {
            $table->text('encrypted_api_key')->nullable()->after('model_name');
            $table->string('api_key_masked', 40)->nullable()->after('encrypted_api_key');
            $table->string('endpoint_url', 255)->nullable()->after('api_key_masked');
            $table->json('supported_models')->nullable()->after('endpoint_url');
            $table->json('capabilities')->nullable()->after('supported_models');
            $table->string('fallback_provider_key', 40)->nullable()->after('capabilities');
            $table->unsignedInteger('timeout_seconds')->default(15)->after('fallback_provider_key');
            $table->unsignedTinyInteger('max_retries')->default(2)->after('timeout_seconds');
            $table->unsignedInteger('rate_limit_rpm')->default(60)->after('max_retries');
            $table->string('connection_status', 40)->default('UNCONFIGURED')->after('rate_limit_rpm');
            $table->timestamp('last_validated_at')->nullable()->after('connection_status');
            $table->string('last_error_code', 64)->nullable()->after('last_validated_at');
            $table->string('last_error_message', 255)->nullable()->after('last_error_code');
        });

        Schema::create('ai_task_routes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('task_type', 64);
            $table->string('primary_provider_key', 40);
            $table->string('primary_model_name', 80);
            $table->string('fallback_provider_key', 40)->nullable();
            $table->string('fallback_model_name', 80)->nullable();
            $table->decimal('temperature', 4, 2)->default(0.10);
            $table->unsignedInteger('max_output_tokens')->default(2000);
            $table->unsignedInteger('timeout_seconds')->default(15);
            $table->unsignedTinyInteger('max_retries')->default(2);
            $table->decimal('quality_threshold', 4, 2)->default(0.70);
            $table->boolean('require_structured_output')->default(true);
            $table->boolean('require_human_review')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'task_type']);
        });

        Schema::table('ai_task_runs', function (Blueprint $table): void {
            $table->string('execution_mode', 40)->default('local_governed_analyzer')->after('model_name');
            $table->boolean('fallback_used')->default(false)->after('execution_mode');
            $table->string('primary_provider_key', 40)->nullable()->after('fallback_used');
            $table->unsignedTinyInteger('retry_count')->default(0)->after('primary_provider_key');
            $table->string('error_message', 255)->nullable()->after('error_code');
            $table->string('entity_type', 64)->nullable()->after('correlation_id');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
        });

        Schema::create('resume_parse_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('candidate_document_id')->constrained('candidate_documents')->cascadeOnDelete();
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->unsignedBigInteger('ai_task_run_id')->nullable()->index();
            $table->string('idempotency_key', 120);
            $table->string('status', 32)->default('UPLOADED')->index();
            $table->string('schema_version', 32)->default('RESUME_SCHEMA_v1.0');
            $table->string('provider_key', 40)->nullable();
            $table->string('model_name', 80)->nullable();
            $table->string('execution_mode', 40)->nullable();
            $table->boolean('ocr_used')->default(false);
            $table->boolean('is_scanned_document')->default(false);
            $table->boolean('prompt_injection_detected')->default(false);
            $table->json('security_flags')->nullable();
            $table->unsignedInteger('extracted_text_length')->default(0);
            $table->json('parsed_data')->nullable();
            $table->json('corrected_data')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('missing_fields')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->boolean('candidate_reviewed')->default(false);
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'candidate_id', 'status']);
        });

        Schema::table('candidates', function (Blueprint $table): void {
            $table->text('professional_summary')->nullable()->after('skills');
            $table->json('work_experience')->nullable()->after('parsed_resume_data');
            $table->json('education')->nullable()->after('work_experience');
            $table->json('certifications')->nullable()->after('education');
            $table->json('projects')->nullable()->after('certifications');
            $table->json('languages')->nullable()->after('projects');
            $table->json('professional_links')->nullable()->after('languages');
            $table->string('resume_parse_status', 32)->nullable()->after('professional_links');
            $table->timestamp('resume_parsed_at')->nullable()->after('resume_parse_status');
            $table->string('resume_schema_version', 32)->nullable()->after('resume_parsed_at');
        });

        Schema::table('applications', function (Blueprint $table): void {
            $table->decimal('ai_match_score', 5, 2)->nullable()->after('screening_score');
            $table->json('ai_match_data')->nullable()->after('ai_match_score');
            $table->boolean('ai_match_reviewed')->default(false)->after('ai_match_data');
            $table->foreignId('ai_match_reviewed_by_user_id')->nullable()->after('ai_match_reviewed')->constrained('users')->nullOnDelete();
            $table->timestamp('ai_match_reviewed_at')->nullable()->after('ai_match_reviewed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_match_reviewed_by_user_id');
            $table->dropColumn(['ai_match_score', 'ai_match_data', 'ai_match_reviewed', 'ai_match_reviewed_at']);
        });

        Schema::table('candidates', function (Blueprint $table): void {
            $table->dropColumn([
                'professional_summary',
                'work_experience',
                'education',
                'certifications',
                'projects',
                'languages',
                'professional_links',
                'resume_parse_status',
                'resume_parsed_at',
                'resume_schema_version',
            ]);
        });

        Schema::dropIfExists('resume_parse_jobs');

        Schema::table('ai_task_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'execution_mode',
                'fallback_used',
                'primary_provider_key',
                'retry_count',
                'error_message',
                'entity_type',
                'entity_id',
            ]);
        });

        Schema::dropIfExists('ai_task_routes');

        Schema::table('ai_provider_configs', function (Blueprint $table): void {
            $table->dropColumn([
                'encrypted_api_key',
                'api_key_masked',
                'endpoint_url',
                'supported_models',
                'capabilities',
                'fallback_provider_key',
                'timeout_seconds',
                'max_retries',
                'rate_limit_rpm',
                'connection_status',
                'last_validated_at',
                'last_error_code',
                'last_error_message',
            ]);
        });
    }
};
