<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('country_code', 2)->default('US');
            $table->string('currency_code', 3)->default('USD');
            $table->string('dynamics_company_code', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('cost_centre_code', 48)->nullable();
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('city', 100);
            $table->string('country_code', 2)->default('US');
            $table->string('timezone', 64)->default('UTC');
            $table->string('work_mode', 24)->default('HYBRID');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('requisition_number', 40);
            $table->string('title', 180);
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->restrictOnDelete();
            $table->foreignId('hiring_manager_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recruiter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('headcount')->default(1);
            $table->unsignedInteger('filled_headcount')->default(0);
            $table->string('employment_type', 32)->default('FULL_TIME');
            $table->string('requisition_reason', 32)->default('NEW_ROLE');
            $table->string('budget_reference', 80);
            $table->decimal('salary_min', 12, 2);
            $table->decimal('salary_max', 12, 2);
            $table->string('currency_code', 3)->default('USD');
            $table->date('target_start_date')->nullable();
            $table->text('justification');
            $table->string('status', 32)->default('DRAFT')->index();
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('approved_version')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('hold_reason', 255)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'requisition_number']);
        });

        Schema::create('requisition_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->string('change_summary', 255)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['requisition_id', 'version_number']);
        });

        Schema::create('requisition_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->unsignedInteger('step_order')->default(1);
            $table->foreignId('approver_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32)->default('PENDING');
            $table->text('comments')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_approvals');
        Schema::dropIfExists('requisition_versions');
        Schema::dropIfExists('requisitions');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('legal_entities');
    }
};
