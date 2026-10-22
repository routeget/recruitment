<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_configs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider_key', 40); // claude, gemini, openai, deterministic_fake
            $table->string('model_name', 80);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->bigInteger('monthly_budget_minor')->default(50000); // $500.00 default
            $table->bigInteger('current_spend_minor')->default(0);
            $table->boolean('kill_switch_engaged')->default(false);
            $table->boolean('redaction_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider_key']);
        });

        Schema::create('ai_prompt_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('task_type', 64);
            $table->unsignedInteger('version')->default(1);
            $table->longText('system_prompt');
            $table->json('output_schema');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'task_type', 'version']);
        });

        Schema::create('ai_task_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task_type', 64)->index();
            $table->string('provider_key', 40);
            $table->string('model_name', 80);
            $table->unsignedInteger('prompt_version')->default(1);
            $table->string('input_hash', 64);
            $table->text('redacted_input_summary');
            $table->json('structured_output')->nullable();
            $table->boolean('schema_valid')->default(true);
            $table->boolean('human_reviewed')->default(false);
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('latency_ms')->default(12);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->bigInteger('cost_minor')->default(0);
            $table->string('status', 32)->default('SUCCEEDED');
            $table->string('error_code', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('api_clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('client_id', 80)->unique();
            $table->string('client_secret_hash', 64);
            $table->json('scopes');
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->string('method', 16);
            $table->string('path', 200);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('target_url', 255);
            $table->string('signing_secret', 120);
            $table->json('subscribed_events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('webhook_subscription_id')->constrained('webhook_subscriptions')->cascadeOnDelete();
            $table->string('event_id', 80);
            $table->string('event_type', 100);
            $table->json('payload');
            $table->string('signature_header', 160);
            $table->unsignedSmallInteger('response_status')->default(200);
            $table->unsignedInteger('attempt_count')->default(1);
            $table->string('status', 32)->default('DELIVERED');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dynamics_integration_configs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('environment_url', 255)->default('https://sandbox.operations.dynamics.com');
            $table->string('connection_mode', 40)->default('alpha_sandbox'); // alpha_sandbox, dry_run, live_configured
            $table->boolean('real_employee_creation_enabled')->default(false);
            $table->json('legal_entity_mappings');
            $table->json('field_ownership_matrix');
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_status', 40)->default('SANDBOX_READY');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dynamics_integration_configs');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('api_idempotency_records');
        Schema::dropIfExists('api_clients');
        Schema::dropIfExists('ai_task_runs');
        Schema::dropIfExists('ai_prompt_versions');
        Schema::dropIfExists('ai_provider_configs');
    }
};
