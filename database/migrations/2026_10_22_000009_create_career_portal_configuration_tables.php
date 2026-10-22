<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('career_portal_configurations')) {
            Schema::create('career_portal_configurations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('scope', 24)->default('GLOBAL')->index();
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('status', 32)->default('PUBLISHED')->index();
                $table->unsignedInteger('schema_version')->default(1);
                $table->unsignedInteger('draft_version')->default(1);
                $table->json('draft_payload');
                $table->string('validation_status', 24)->default('VALID');
                $table->json('validation_errors')->nullable();
                $table->timestamp('last_validated_at')->nullable();
                $table->unsignedBigInteger('active_published_version_id')->nullable()->index();
                $table->json('published_payload')->nullable();
                $table->unsignedInteger('published_version_number')->default(1);
                $table->timestamp('published_at')->nullable();
                $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('preview_token_hash', 128)->nullable();
                $table->timestamp('preview_token_expires_at')->nullable();
                $table->timestamps();

                $table->unique(['scope', 'tenant_id'], 'career_portal_cfg_scope_tenant_unique');
            });
        }

        if (! Schema::hasTable('career_portal_configuration_versions')) {
            Schema::create('career_portal_configuration_versions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('configuration_id')->constrained('career_portal_configurations')->cascadeOnDelete();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->unsignedInteger('version_number');
                $table->string('status', 24)->default('PUBLISHED')->index();
                $table->unsignedInteger('schema_version')->default(1);
                $table->json('snapshot');
                $table->json('validation_summary')->nullable();
                $table->string('change_summary', 500)->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['configuration_id', 'version_number'], 'career_portal_cfg_ver_unique');
            });
        }

        if (! Schema::hasTable('career_portal_publication_events')) {
            Schema::create('career_portal_publication_events', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('configuration_id')->constrained('career_portal_configurations')->cascadeOnDelete();
                $table->unsignedBigInteger('version_id')->nullable()->index();
                $table->unsignedBigInteger('restored_from_version_id')->nullable();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('action', 40)->index();
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->string('actor_identifier', 190)->nullable();
                $table->string('correlation_id', 80)->nullable()->index();
                $table->string('change_summary', 500)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('career_portal_assets')) {
            Schema::create('career_portal_assets', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
                $table->string('asset_key', 120)->index();
                $table->string('title', 160);
                $table->string('alt_text', 255);
                $table->string('public_path', 255);
                $table->string('mime_type', 80)->default('image/jpeg');
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedTinyInteger('focal_point_x')->default(50);
                $table->unsignedTinyInteger('focal_point_y')->default(50);
                $table->string('approval_status', 24)->default('APPROVED')->index();
                $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('job_postings')) {
            Schema::table('job_postings', function (Blueprint $table) {
                if (! Schema::hasColumn('job_postings', 'category_slug')) {
                    $table->string('category_slug', 80)->nullable()->after('experience_level')->index();
                }
                if (! Schema::hasColumn('job_postings', 'is_featured')) {
                    $table->boolean('is_featured')->default(false)->after('show_compensation_publicly')->index();
                }
            });
        }

        if (Schema::hasTable('permissions')) {
            $now = now();
            foreach ([
                ['slug' => 'career_portal.configure', 'name' => 'Configure Career Portal', 'module' => 'career_portal'],
                ['slug' => 'career_portal.publish', 'name' => 'Publish Career Portal Configuration', 'module' => 'career_portal'],
            ] as $perm) {
                DB::table('permissions')->updateOrInsert(
                    ['slug' => $perm['slug']],
                    [
                        'name' => $perm['name'],
                        'module' => $perm['module'],
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('job_postings')) {
            Schema::table('job_postings', function (Blueprint $table) {
                if (Schema::hasColumn('job_postings', 'is_featured')) {
                    $table->dropColumn('is_featured');
                }
                if (Schema::hasColumn('job_postings', 'category_slug')) {
                    $table->dropColumn('category_slug');
                }
            });
        }

        Schema::dropIfExists('career_portal_assets');
        Schema::dropIfExists('career_portal_publication_events');
        Schema::dropIfExists('career_portal_configuration_versions');
        Schema::dropIfExists('career_portal_configurations');
    }
};
