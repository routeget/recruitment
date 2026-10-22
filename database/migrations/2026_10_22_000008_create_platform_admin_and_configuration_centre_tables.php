<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'status')) {
                $table->string('status', 32)->default('ACTIVE')->after('email')->index();
            }
            if (! Schema::hasColumn('users', 'platform_role')) {
                $table->string('platform_role', 64)->nullable()->after('is_platform_superadmin')->index();
            }
            if (! Schema::hasColumn('users', 'platform_admin_granted_at')) {
                $table->timestamp('platform_admin_granted_at')->nullable()->after('platform_role');
            }
            if (! Schema::hasColumn('users', 'platform_admin_granted_by')) {
                $table->string('platform_admin_granted_by', 190)->nullable()->after('platform_admin_granted_at');
            }
            if (! Schema::hasColumn('users', 'platform_admin_reason')) {
                $table->string('platform_admin_reason', 500)->nullable()->after('platform_admin_granted_by');
            }
            if (! Schema::hasColumn('users', 'must_rotate_password')) {
                $table->boolean('must_rotate_password')->default(false)->after('mfa_enabled');
            }
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('must_rotate_password');
            }
            if (! Schema::hasColumn('users', 'mfa_enrolled_at')) {
                $table->timestamp('mfa_enrolled_at')->nullable()->after('password_changed_at');
            }
        });

        if (! Schema::hasTable('platform_settings')) {
            Schema::create('platform_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 120)->unique();
                $table->string('section', 64)->index();
                $table->json('value_json');
                $table->string('value_type', 32);
                $table->string('classification', 48)->default('PLATFORM_DB_SETTING');
                $table->string('sensitivity', 32)->default('INTERNAL');
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('last_change_reason', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');

        Schema::table('users', function (Blueprint $table) {
            $columns = [
                'status',
                'platform_role',
                'platform_admin_granted_at',
                'platform_admin_granted_by',
                'platform_admin_reason',
                'must_rotate_password',
                'password_changed_at',
                'mfa_enrolled_at',
            ];
            $existing = array_filter($columns, fn (string $col) => Schema::hasColumn('users', $col));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
