<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('requisition_id')->constrained('requisitions')->restrictOnDelete();
            $table->string('slug', 160);
            $table->string('code', 40);
            $table->string('title', 180);
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('employment_type', 32)->default('FULL_TIME');
            $table->string('work_mode', 24)->default('HYBRID');
            $table->string('experience_level', 32)->default('SENIOR');
            $table->text('summary');
            $table->text('responsibilities');
            $table->text('qualifications');
            $table->json('skills_required')->nullable();
            $table->decimal('compensation_min', 12, 2)->nullable();
            $table->decimal('compensation_max', 12, 2)->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->boolean('show_compensation_publicly')->default(true);
            $table->string('status', 32)->default('DRAFT')->index();
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('approved_version')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->json('external_channels_config')->nullable();
            $table->string('seo_title', 190)->nullable();
            $table->string('seo_description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('job_posting_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained('job_postings')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->string('change_summary', 255)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['job_posting_id', 'version_number']);
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('candidate_code', 40);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 190)->index();
            $table->string('phone', 40)->nullable();
            $table->string('location_city', 100)->nullable();
            $table->string('country_code', 2)->default('US');
            $table->string('current_title', 150)->nullable();
            $table->unsignedInteger('years_experience')->default(0);
            $table->json('skills')->nullable();
            $table->json('parsed_resume_data')->nullable();
            $table->string('source_channel', 40)->default('CAREER_PORTAL');
            $table->string('talent_pool_tag', 80)->nullable();
            $table->boolean('consent_given')->default(false);
            $table->string('consent_version', 32)->default('PRIVACY-v2.0');
            $table->timestamp('consent_given_at')->nullable();
            $table->boolean('privacy_notice_acknowledged')->default(false);
            $table->string('dsar_status', 32)->default('NONE');
            $table->timestamp('retention_expires_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'candidate_code']);
        });

        Schema::create('candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->string('document_type', 40)->default('RESUME');
            $table->string('original_filename', 255);
            $table->string('storage_disk', 32)->default('local');
            $table->string('storage_path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size_bytes');
            $table->char('sha256_checksum', 64);
            $table->string('malware_scan_status', 48)->default('CLEAN_VERIFIED');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retention_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('application_number', 40);
            $table->foreignId('job_posting_id')->constrained('job_postings')->restrictOnDelete();
            $table->foreignId('requisition_id')->constrained('requisitions')->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('assigned_recruiter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('current_stage', 48)->default('RECEIVED')->index();
            $table->string('candidate_visible_status', 80)->default('Application Received');
            $table->decimal('screening_score', 5, 2)->nullable();
            $table->boolean('eligibility_verified')->default(false);
            $table->text('screening_notes')->nullable();
            $table->text('cover_note')->nullable();
            $table->json('answers')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'application_number']);
            $table->unique(['tenant_id', 'job_posting_id', 'candidate_id']);
        });

        Schema::create('application_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->string('from_stage', 48)->nullable();
            $table->string('to_stage', 48);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_stage_events');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('candidate_documents');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('job_posting_versions');
        Schema::dropIfExists('job_postings');
    }
};
