<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_url', 2048)->nullable();
            $table->string('original_publisher')->nullable();
            $table->date('announced_on')->nullable()->index();
            $table->enum('verification_status', ['unverified', 'verified', 'unavailable'])->default('unverified');
            $table->date('verified_on')->nullable();
            $table->foreignId('merged_into_event_id')->nullable()->constrained('research_events');
            $table->timestamps();
        });

        Schema::create('research_discovery_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_event_id')->constrained('research_events');
            $table->enum('route_type', ['manual'])->default('manual');
            $table->string('url', 2048);
            $table->char('url_hash', 64)->index();
            $table->string('source_title');
            $table->string('publisher');
            $table->date('posted_on')->nullable();
            $table->date('checked_on');
            $table->text('summary');
            $table->string('sponsorship_note', 2000)->nullable();
            $table->timestamps();
            $table->unique(['research_event_id', 'url_hash'], 'research_discovery_event_url_unique');
        });

        Schema::create('research_entities', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name')->index();
            $table->enum('identification_status', ['unidentified', 'identified', 'unlisted', 'foreign_only', 'ambiguous'])->default('unidentified');
            $table->string('identification_note', 2000)->nullable();
            $table->timestamps();
        });

        Schema::create('research_entity_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_entity_id')->constrained('research_entities');
            $table->enum('market', ['jp', 'us']);
            $table->string('symbol_code', 20);
            $table->string('listed_entity_name');
            $table->string('source_url', 2048);
            $table->date('confirmed_on');
            $table->timestamps();
            $table->index(['market', 'symbol_code']);
        });

        Schema::create('research_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_event_id')->constrained('research_events');
            $table->foreignId('research_entity_id')->constrained('research_entities');
            $table->string('theme', 100)->index();
            $table->string('theme_relation', 2000);
            $table->string('entity_role')->nullable();
            $table->enum('evidence_stage', ['unknown', 'research', 'product', 'regulatory', 'plan', 'operation', 'order', 'revenue'])->default('unknown');
            $table->string('counter_evidence', 2000)->nullable();
            $table->date('counter_evidence_checked_on')->nullable();
            $table->enum('status', ['investigating', 'on_hold', 'rejected', 'watchlisted'])->default('investigating');
            $table->string('status_reason', 2000)->nullable();
            $table->boolean('needs_recheck')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['research_event_id', 'research_entity_id'], 'research_candidate_event_entity_unique');
            $table->index(['status', 'archived_at']);
            $table->index('created_at');
        });

        Schema::create('research_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_candidate_id')->constrained('research_candidates');
            $table->string('claim', 2000);
            $table->string('evidence_url', 2048)->nullable();
            $table->enum('status', ['unverified', 'verified', 'contradicted', 'unavailable'])->default('unverified');
            $table->boolean('is_primary_source')->default(false);
            $table->date('checked_on')->nullable();
            $table->timestamps();
        });

        Schema::create('research_candidate_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_candidate_id')->constrained('research_candidates');
            $table->unsignedInteger('lock_version');
            $table->enum('change_type', ['created', 'updated', 'status_changed', 'merged', 'unmerged', 'archived', 'restored', 'handed_off']);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['research_candidate_id', 'lock_version'], 'research_revision_candidate_version_unique');
        });

        Schema::create('research_watchlist_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_candidate_id')->constrained('research_candidates');
            $table->foreignId('research_candidate_revision_id')->constrained('research_candidate_revisions', indexName: 'research_handoff_revision_fk');
            $table->foreignId('research_entity_listing_id')->constrained('research_entity_listings');
            $table->foreignId('holding_id')->constrained('holdings');
            $table->foreignId('watchlist_item_id')->nullable()->constrained('watchlist_items');
            $table->enum('outcome', ['created', 'linked_existing', 'already_held']);
            $table->foreignId('matched_snapshot_id')->nullable()->constrained('snapshots');
            $table->timestamp('favorites_last_imported_at')->nullable();
            $table->char('idempotency_key', 36);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['research_candidate_id', 'idempotency_key'], 'research_handoff_candidate_key_unique');
        });
    }

    public function down(): void
    {
        foreach (['research_watchlist_handoffs', 'research_candidate_revisions', 'research_claims', 'research_candidates', 'research_entity_listings', 'research_entities', 'research_discovery_links', 'research_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
