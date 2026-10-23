<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resume_parse_jobs', function (Blueprint $table): void {
            $table->dropForeign(['candidate_document_id']);
            $table->unsignedBigInteger('candidate_document_id')->nullable()->change();
            $table->foreign('candidate_document_id')
                ->references('id')
                ->on('candidate_documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('resume_parse_jobs', function (Blueprint $table): void {
            $table->dropForeign(['candidate_document_id']);
            $table->unsignedBigInteger('candidate_document_id')->nullable(false)->change();
            $table->foreign('candidate_document_id')
                ->references('id')
                ->on('candidate_documents')
                ->cascadeOnDelete();
        });
    }
};
