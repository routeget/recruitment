<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 80)->unique();
            $table->string('name', 160);
            $table->string('primary_domain', 190)->nullable()->unique();
            $table->string('default_timezone', 64)->default('UTC');
            $table->string('default_currency', 3)->default('USD');
            $table->string('default_locale', 16)->default('en');
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->json('branding_config')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('retention_days')->default(365);
            $table->boolean('ai_kill_switch')->default(false);
            $table->decimal('ai_monthly_budget_usd', 10, 2)->default(500.00);
            $table->boolean('mcp_enabled')->default(false);
            $table->boolean('dynamics_live_enabled')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->foreignId('current_tenant_id')->nullable()->after('password')->constrained('tenants')->nullOnDelete();
            $table->boolean('is_platform_superadmin')->default(false)->after('current_tenant_id');
            $table->boolean('mfa_enabled')->default(false)->after('is_platform_superadmin');
            $table->timestamp('last_login_at')->nullable()->after('mfa_enabled');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 150);
            $table->string('module', 64)->index();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->json('abac_scope')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'role_id']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('correlation_id', 64)->index();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 32)->default('USER');
            $table->string('actor_identifier', 190)->nullable();
            $table->string('module', 64)->index();
            $table->string('action', 100)->index();
            $table->string('entity_type', 100)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->string('outcome', 32)->default('SUCCESS')->index();
            $table->string('error_code', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_tenant_id');
            $table->dropColumn(['uuid', 'is_platform_superadmin', 'mfa_enabled', 'last_login_at']);
        });

        Schema::dropIfExists('tenants');
    }
};
