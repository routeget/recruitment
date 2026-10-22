<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenants', 'require_sso')) {
                $table->boolean('require_sso')->default(false)->after('dynamics_live_enabled');
            }
            if (! Schema::hasColumn('tenants', 'allow_local_fallback')) {
                $table->boolean('allow_local_fallback')->default(true)->after('require_sso');
            }
        });

        Schema::table('requisitions', function (Blueprint $table): void {
            $table->unsignedBigInteger('hiring_manager_user_id')->nullable()->change();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
        });

        Schema::table('requisition_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by_user_id')->nullable()->change();
        });

        Schema::create('external_identity_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 40)->default('microsoft_entra_id');
            $table->string('display_name', 150);
            $table->string('entra_tenant_id', 64)->index();
            $table->string('client_id', 100)->nullable();
            $table->json('allowed_domains')->nullable();
            $table->boolean('jit_provisioning_enabled')->default(false);
            $table->string('default_jit_role_slug', 64)->default('hiring_manager');
            $table->boolean('require_sso_for_tenant')->default(false);
            $table->boolean('allow_emergency_local_login')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'entra_tenant_id'], 'ext_id_conn_provider_tid_unique');
            $table->index(['tenant_id', 'is_enabled']);
        });

        Schema::create('external_identities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('external_identity_connections')->cascadeOnDelete();
            $table->string('provider', 40)->default('microsoft_entra_id');
            $table->string('entra_tenant_id', 64);
            $table->string('entra_object_id', 64);
            $table->string('subject', 128);
            $table->string('preferred_username', 190)->nullable();
            $table->string('email_claim', 190)->nullable();
            $table->string('display_name_claim', 160)->nullable();
            $table->string('status', 32)->default('ACTIVE')->index();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_authenticated_at')->nullable();
            $table->json('last_claims_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'entra_tenant_id', 'entra_object_id'], 'ext_identities_provider_tid_oid_unique');
            $table->unique(['connection_id', 'user_id'], 'ext_identities_conn_user_unique');
        });

        Schema::create('sso_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('external_identity_connections')->cascadeOnDelete();
            $table->string('email', 190)->index();
            $table->string('expected_entra_object_id', 64)->nullable();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 32)->default('PENDING')->index();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('api_idempotency_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_idempotency_records', 'status')) {
                $table->string('status', 24)->default('COMPLETED')->after('request_hash');
            }
        });

        Schema::table('webhook_subscriptions', function (Blueprint $table): void {
            $table->text('signing_secret')->change();
        });

        // Encrypt any pre-existing plaintext webhook signing secrets in place
        $existingSubs = DB::table('webhook_subscriptions')->get(['id', 'signing_secret']);
        foreach ($existingSubs as $sub) {
            if (is_string($sub->signing_secret) && str_starts_with($sub->signing_secret, 'whsec_')) {
                DB::table('webhook_subscriptions')
                    ->where('id', $sub->id)
                    ->update(['signing_secret' => Crypt::encryptString($sub->signing_secret)]);
            }
        }

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->unsignedSmallInteger('response_status')->nullable()->default(null)->change();
            $table->unsignedInteger('attempt_count')->default(0)->change();
            $table->string('status', 32)->default('PENDING')->change();
            if (! Schema::hasColumn('webhook_deliveries', 'error_code')) {
                $table->string('error_code', 64)->nullable()->after('status');
            }
            if (! Schema::hasColumn('webhook_deliveries', 'error_message')) {
                $table->string('error_message', 500)->nullable()->after('error_code');
            }
            if (! Schema::hasColumn('webhook_deliveries', 'response_body_excerpt')) {
                $table->text('response_body_excerpt')->nullable()->after('error_message');
            }
            if (! Schema::hasColumn('webhook_deliveries', 'queued_at')) {
                $table->timestamp('queued_at')->nullable()->after('response_body_excerpt');
            }
            if (! Schema::hasColumn('webhook_deliveries', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn([
                'error_code',
                'error_message',
                'response_body_excerpt',
                'queued_at',
                'failed_at',
            ]);
        });

        Schema::table('api_idempotency_records', function (Blueprint $table): void {
            if (Schema::hasColumn('api_idempotency_records', 'status')) {
                $table->dropColumn('status');
            }
        });

        Schema::dropIfExists('sso_invitations');
        Schema::dropIfExists('external_identities');
        Schema::dropIfExists('external_identity_connections');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['require_sso', 'allow_local_fallback']);
        });
    }
};
