<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('offer_templates');

        Schema::create('offer_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name', 160);
            $table->longText('body_template');
            $table->json('compensation_rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('accepted_version_id')->nullable()->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('offer_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('job_title', 200);
            $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('employment_type', 40)->default('full_time');
            $table->bigInteger('base_salary_minor');
            $table->string('currency_code', 3)->default('USD');
            $table->text('bonus_terms')->nullable();
            $table->text('equity_terms')->nullable();
            $table->date('start_date');
            $table->unsignedSmallInteger('probation_months')->default(3);
            $table->string('document_storage_key', 255)->nullable();
            $table->string('snapshot_hash', 64);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['offer_id', 'version_number']);
        });

        Schema::create('hr_validation_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('offer_version_id')->constrained('offer_versions')->cascadeOnDelete();
            $table->string('status', 48)->default('not_started')->index();
            $table->unsignedInteger('checklist_version')->default(1);
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamp('right_to_work_verified_at')->nullable();
            $table->string('background_check_status', 40)->default('pending');
            $table->timestamp('compensation_validated_at')->nullable();
            $table->timestamp('position_mapping_validated_at')->nullable();
            $table->timestamp('legal_entity_validated_at')->nullable();
            $table->timestamp('start_date_confirmed_at')->nullable();
            $table->string('position_code', 64)->nullable();
            $table->foreignId('assigned_to_hr_validator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_handover_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('hr_validation_case_id')->constrained('hr_validation_cases')->cascadeOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 32);
            $table->text('comment')->nullable();
            $table->json('approved_payload_snapshot');
            $table->string('approved_payload_hash', 64);
            $table->timestamp('decided_at')->useCurrent();

            $table->index(['tenant_id', 'hr_validation_case_id']);
        });

        Schema::create('hr_handovers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('hr_validation_case_id')->constrained('hr_validation_cases')->cascadeOnDelete();
            $table->foreignId('approval_id')->constrained('hr_handover_approvals')->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->string('status', 48)->default('ready_for_dispatch')->index();
            $table->string('target_system', 64)->default('dynamics_365_finance_hr');
            $table->json('payload_snapshot');
            $table->string('payload_hash', 64);
            $table->string('external_request_id', 120)->nullable();
            $table->string('external_employee_id', 120)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_detail')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->boolean('requires_reconciliation')->default(false);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('transactional_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('aggregate_type', 80);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 100)->index();
            $table->string('idempotency_key', 120);
            $table->json('payload');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('dispatched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('external_employee_references', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->foreignId('hr_handover_id')->constrained('hr_handovers')->cascadeOnDelete();
            $table->string('external_system', 64)->default('dynamics_365_finance_hr');
            $table->string('external_employee_id', 120);
            $table->string('external_worker_number', 80);
            $table->string('legal_entity_code', 32);
            $table->timestamp('synced_at')->useCurrent();
            $table->string('sync_status', 40)->default('synced');
            $table->json('raw_reference_payload')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'external_system', 'external_employee_id'], 'ext_emp_ref_tenant_sys_emp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_employee_references');
        Schema::dropIfExists('transactional_outbox_events');
        Schema::dropIfExists('hr_handovers');
        Schema::dropIfExists('hr_handover_approvals');
        Schema::dropIfExists('hr_validation_cases');
        Schema::dropIfExists('offer_versions');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('offer_templates');
    }
};
