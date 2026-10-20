<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_automation_runs', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('trigger', 20);
            $table->unsignedBigInteger('actor_id')->nullable()->index('rar_actor_idx');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20)->default('running');
            $table->json('counters');
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['started_at', 'id'], 'rar_started_idx');
            $table->foreign('actor_id', 'rar_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('research_source_documents', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('source_item_id')->unique('rsd_source_item_unique');
            $table->unsignedBigInteger('latest_feed_version_id')->nullable()->index('rsd_feed_idx');
            $table->unsignedBigInteger('latest_content_version_id')->nullable()->index('rsd_content_idx');
            $table->unsignedBigInteger('last_seen_check_id')->nullable()->index('rsd_check_idx');
            $table->string('extraction_state', 24)->default('pending');
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedBigInteger('extracted_version_id')->nullable()->index('rsd_extracted_idx');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->index(['extraction_state', 'next_attempt_at', 'id'], 'rsd_pending_idx');
            $table->foreign('source_item_id', 'rsd_item_fk')->references('id')->on('research_source_items')->restrictOnDelete();
            $table->foreign('last_seen_check_id', 'rsd_check_fk')->references('id')->on('research_source_checks')->restrictOnDelete();
        });

        Schema::create('research_document_attempts', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('run_id')->index('rda_run_idx');
            $table->unsignedBigInteger('document_id')->index('rda_document_idx');
            $table->string('phase', 20);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 24)->default('running');
            $table->unsignedBigInteger('input_version_id')->nullable()->index('rda_input_idx');
            $table->unsignedBigInteger('output_version_id')->nullable()->index('rda_output_idx');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_bytes')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['document_id', 'started_at', 'id'], 'rda_document_started_idx');
            $table->foreign('run_id', 'rda_run_fk')->references('id')->on('research_automation_runs')->restrictOnDelete();
            $table->foreign('document_id', 'rda_document_fk')->references('id')->on('research_source_documents')->restrictOnDelete();
        });

        Schema::create('research_document_versions', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('document_id')->index('rdv_document_idx');
            $table->string('phase', 20);
            $table->unsignedInteger('version_no');
            $table->unsignedBigInteger('parent_feed_version_id')->nullable()->index('rdv_parent_feed_idx');
            $table->unsignedBigInteger('observed_check_id')->nullable()->index('rdv_check_idx');
            $table->unsignedBigInteger('attempt_id')->nullable()->index('rdv_attempt_idx');
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->string('parser_version', 40);
            $table->string('rule_version', 40);
            $table->timestamp('observed_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['document_id', 'version_no'], 'rdv_document_version_unique');
            $table->index(['document_id', 'phase', 'version_no'], 'rdv_document_phase_idx');
            $table->foreign('document_id', 'rdv_document_fk')->references('id')->on('research_source_documents')->restrictOnDelete();
            $table->foreign('observed_check_id', 'rdv_check_fk')->references('id')->on('research_source_checks')->restrictOnDelete();
            $table->foreign('attempt_id', 'rdv_attempt_fk')->references('id')->on('research_document_attempts')->restrictOnDelete();
        });

        // Add circular document/version pointers after both sides exist.
        Schema::table('research_document_versions', function (Blueprint $table) {
            $table->foreign('parent_feed_version_id', 'rdv_parent_feed_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
        });
        Schema::table('research_source_documents', function (Blueprint $table) {
            $table->foreign('latest_feed_version_id', 'rsd_feed_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
            $table->foreign('latest_content_version_id', 'rsd_content_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
            $table->foreign('extracted_version_id', 'rsd_extracted_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
        });
        Schema::table('research_document_attempts', function (Blueprint $table) {
            $table->foreign('input_version_id', 'rda_input_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
            $table->foreign('output_version_id', 'rda_output_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
        });

        Schema::create('research_candidate_drafts', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('document_id')->index('rcd_document_idx');
            $table->unsignedBigInteger('latest_version_id')->index('rcd_latest_idx');
            $table->unsignedBigInteger('current_document_version_id')->index('rcd_current_idx');
            $table->char('mention_key', 64);
            $table->string('raw_name', 255);
            $table->string('normalized_name', 255)->index('rcd_name_idx');
            $table->json('extracted_fields');
            $table->json('human_overrides')->nullable();
            $table->string('disposition', 20)->default('unreviewed');
            $table->string('reason', 2000)->nullable();
            $table->string('presence', 20)->default('present');
            $table->timestamp('first_detected_at');
            $table->timestamp('last_observed_at');
            $table->string('discovery_kind', 20);
            $table->unsignedBigInteger('reviewed_version_id')->nullable()->index('rcd_reviewed_version_idx');
            $table->date('reviewed_on')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index('rcd_reviewed_by_idx');
            $table->unsignedBigInteger('imported_candidate_id')->nullable()->index('rcd_candidate_idx');
            $table->unsignedBigInteger('imported_link_id')->nullable()->index('rcd_link_idx');
            $table->unsignedBigInteger('imported_version_id')->nullable()->index('rcd_imported_version_idx');
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedInteger('match_generation')->default(0);
            $table->char('match_input_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'mention_key'], 'rcd_document_mention_unique');
            $table->index(['disposition', 'first_detected_at', 'id'], 'rcd_disposition_detected_idx');
            $table->index(['presence', 'disposition'], 'rcd_presence_disposition_idx');
            $table->foreign('document_id', 'rcd_document_fk')->references('id')->on('research_source_documents')->restrictOnDelete();
            $table->foreign('latest_version_id', 'rcd_latest_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
            $table->foreign('current_document_version_id', 'rcd_current_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
            $table->foreign('reviewed_version_id', 'rcd_reviewed_version_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
            $table->foreign('reviewed_by', 'rcd_reviewed_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('imported_candidate_id', 'rcd_candidate_fk')->references('id')->on('research_candidates')->restrictOnDelete();
            $table->foreign('imported_link_id', 'rcd_link_fk')->references('id')->on('research_discovery_links')->restrictOnDelete();
            $table->foreign('imported_version_id', 'rcd_imported_version_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
        });

        Schema::create('research_draft_revisions', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('draft_id')->index('rdr_draft_idx');
            $table->unsignedInteger('lock_version');
            $table->string('change_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable()->index('rdr_actor_idx');
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['draft_id', 'lock_version'], 'rdr_draft_version_unique');
            $table->foreign('draft_id', 'rdr_draft_fk')->references('id')->on('research_candidate_drafts')->restrictOnDelete();
            $table->foreign('actor_id', 'rdr_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('research_listing_catalog_imports', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('run_id')->index('rlci_run_idx');
            $table->string('catalog_key', 20);
            $table->string('source_url', 2048);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->date('as_of_on')->nullable();
            $table->string('status', 20)->default('running');
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->index(['catalog_key', 'status', 'published_at', 'id'], 'rlci_catalog_status_idx');
            $table->foreign('run_id', 'rlci_run_fk')->references('id')->on('research_automation_runs')->restrictOnDelete();
        });

        Schema::create('research_listing_catalog_entries', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('import_id')->index('rlce_import_idx');
            $table->string('market', 8);
            $table->string('symbol_code', 20);
            $table->string('issuer_key', 80);
            $table->string('legal_name', 255);
            $table->string('normalized_name', 255);
            $table->char('name_hash', 64);
            $table->string('exchange', 80);
            $table->string('security_type', 80);
            $table->string('reference_url', 2048);
            $table->timestamps();
            $table->unique(['import_id', 'market', 'symbol_code'], 'rlce_import_symbol_unique');
            $table->index(['import_id', 'name_hash'], 'rlce_import_name_idx');
            $table->index(['import_id', 'issuer_key'], 'rlce_import_issuer_idx');
            $table->foreign('import_id', 'rlce_import_fk')->references('id')->on('research_listing_catalog_imports')->restrictOnDelete();
        });

        Schema::create('research_company_aliases', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('raw_name', 255);
            $table->string('normalized_name', 255);
            $table->char('name_hash', 64);
            $table->unsignedBigInteger('target_entry_id')->index('rca_entry_idx');
            $table->string('relationship', 24);
            $table->string('evidence_url', 2048);
            $table->string('note', 2000);
            $table->date('confirmed_on');
            $table->unsignedBigInteger('confirmed_by')->index('rca_confirmed_by_idx');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['name_hash', 'revoked_at'], 'rca_name_revoked_idx');
            $table->foreign('target_entry_id', 'rca_entry_fk')->references('id')->on('research_listing_catalog_entries')->restrictOnDelete();
            $table->foreign('confirmed_by', 'rca_confirmed_by_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('research_draft_listing_matches', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('draft_id')->index('rdlm_draft_idx');
            $table->unsignedBigInteger('entry_id')->index('rdlm_entry_idx');
            $table->unsignedInteger('generation');
            $table->unsignedBigInteger('alias_id')->nullable()->index('rdlm_alias_idx');
            $table->string('match_method', 24);
            $table->string('reason', 2000);
            $table->timestamp('matched_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->unique(['draft_id', 'generation', 'entry_id', 'match_method'], 'rdlm_draft_generation_entry_unique');
            $table->index(['draft_id', 'superseded_at'], 'rdlm_draft_superseded_idx');
            $table->foreign('draft_id', 'rdlm_draft_fk')->references('id')->on('research_candidate_drafts')->restrictOnDelete();
            $table->foreign('entry_id', 'rdlm_entry_fk')->references('id')->on('research_listing_catalog_entries')->restrictOnDelete();
            $table->foreign('alias_id', 'rdlm_alias_fk')->references('id')->on('research_company_aliases')->restrictOnDelete();
        });

        Schema::table('research_discovery_links', function (Blueprint $table) {
            $table->string('input_origin', 20)->default('manual');
            $table->string('generated_summary', 2000)->nullable();
            $table->unsignedBigInteger('source_document_version_id')->nullable()->index('rdl_document_version_idx');
            $table->foreign('source_document_version_id', 'rdl_document_version_fk')->references('id')->on('research_document_versions')->restrictOnDelete();
        });
        Schema::table('research_source_checks', function (Blueprint $table) {
            $table->json('automation_payload')->nullable();
            $table->unsignedInteger('automation_cursor')->nullable();
        });
    }

    public function down(): void
    {
        // For an unused installation only: live records must be retained by disabling the feature.
        Schema::table('research_source_checks', function (Blueprint $table) {
            $table->dropColumn(['automation_payload', 'automation_cursor']);
        });
        Schema::table('research_discovery_links', function (Blueprint $table) {
            $table->dropForeign('rdl_document_version_fk');
            $table->dropColumn(['input_origin', 'generated_summary', 'source_document_version_id']);
        });
        Schema::dropIfExists('research_draft_listing_matches');
        Schema::dropIfExists('research_company_aliases');
        Schema::dropIfExists('research_listing_catalog_entries');
        Schema::dropIfExists('research_listing_catalog_imports');
        Schema::dropIfExists('research_draft_revisions');
        Schema::dropIfExists('research_candidate_drafts');
        Schema::table('research_source_documents', function (Blueprint $table) {
            $table->dropForeign('rsd_feed_fk');
            $table->dropForeign('rsd_content_fk');
            $table->dropForeign('rsd_extracted_fk');
        });
        Schema::table('research_document_attempts', function (Blueprint $table) {
            $table->dropForeign('rda_input_fk');
            $table->dropForeign('rda_output_fk');
        });
        Schema::table('research_document_versions', function (Blueprint $table) {
            $table->dropForeign('rdv_parent_feed_fk');
        });
        Schema::dropIfExists('research_document_versions');
        Schema::dropIfExists('research_document_attempts');
        Schema::dropIfExists('research_source_documents');
        Schema::dropIfExists('research_automation_runs');
    }
};
