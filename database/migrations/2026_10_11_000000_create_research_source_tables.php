<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_source_checks', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('source_key', 20);
            $table->enum('trigger', ['startup', 'manual'])->default('startup');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->enum('result', ['new_items', 'no_new', 'baseline', 'failed'])->nullable();
            $table->unsignedSmallInteger('item_count')->default(0);
            $table->unsignedSmallInteger('new_item_count')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();
            $table->index(['source_key', 'started_at']);
        });

        Schema::create('research_source_items', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('source_key', 20);
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('title', 500);
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('first_check_id')->constrained('research_source_checks');
            $table->boolean('is_baseline')->default(false);
            $table->enum('disposition', ['unreviewed', 'recorded', 'ignored'])->default('unreviewed');
            $table->foreignId('research_discovery_link_id')->nullable()->constrained('research_discovery_links');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_key', 'url_hash']);
            $table->index(['source_key', 'disposition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_source_items');
        Schema::dropIfExists('research_source_checks');
    }
};
