<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Book and metadata languages. Adding a language is a row, not a schema change.
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('tag', 35)->unique(); // BCP 47
            $table->string('native_name', 100);
            $table->string('english_name', 100);
            $table->enum('direction', ['ltr', 'rtl'])->default('ltr');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('contributors', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('name');
            $table->string('sort_name')->index();
            $table->string('name_language_tag', 35)->nullable();
            $table->text('biography')->nullable();
            $table->string('born', 20)->nullable();
            $table->string('died', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('works', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('original_title');
            $table->string('original_language_tag', 35)->index();
            $table->string('first_published', 20)->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->decimal('series_position', 6, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('work_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_work_id')->constrained('works')->cascadeOnDelete();
            $table->string('type', 40)->default('related'); // sequel, adaptation, commentary, related
            $table->unique(['work_id', 'related_work_id', 'type']);
        });

        // An edition is one translation or published version. Several editions may
        // share a language; there is deliberately no unique (work_id, language_tag).
        Schema::create('editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 190)->unique();
            $table->string('language_tag', 35)->index();
            $table->enum('direction', ['ltr', 'rtl', 'auto'])->default('ltr');
            $table->enum('page_progression', ['ltr', 'rtl', 'default'])->default('default');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->string('publisher')->nullable();
            $table->string('published_date', 20)->nullable();
            $table->string('isbn', 32)->nullable()->index();
            $table->string('edition_statement')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('source_name')->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->text('attribution')->nullable();
            $table->string('rights_status', 30)->default('unknown'); // public_domain, open_license, authorized, unknown
            $table->string('license_name')->nullable();
            $table->string('license_url', 2048)->nullable();
            $table->string('rights_holder')->nullable();
            $table->text('rights_notes')->nullable();
            $table->text('territory_notes')->nullable(); // informational only; not enforced
            $table->string('status', 20)->default('draft')->index(); // draft, processing, review, published, withdrawn
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawn_reason')->nullable();
            $table->text('search_text')->nullable();
            $table->timestamps();
            $table->index(['work_id', 'status']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE editions ADD FULLTEXT editions_search_fulltext (search_text)');
        }

        Schema::create('edition_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            $table->string('format', 10); // epub, pdf, txt, html
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64)->index();
            $table->string('storage_disk', 40);
            $table->string('storage_path');
            $table->string('reading_path')->nullable(); // sanitized, extracted reading copy
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->string('original_filename')->nullable();
            $table->string('import_status', 20)->default('quarantined'); // quarantined, processing, ready, failed
            $table->json('validation_report')->nullable();
            $table->string('layout', 20)->default('reflowable'); // reflowable, fixed
            $table->boolean('has_text_layer')->nullable(); // PDF only; null = unknown
            $table->unsignedInteger('page_count')->nullable();
            $table->json('manifest')->nullable(); // reading-copy resource list for offline saving
            $table->boolean('can_read')->default(false);
            $table->boolean('can_download')->default(false);
            $table->boolean('can_offline')->default(false);
            $table->string('license_name')->nullable();
            $table->text('rights_notes')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['edition_id', 'format', 'is_current']);
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contributor_id')->constrained()->cascadeOnDelete();
            $table->morphs('contributable'); // work (authors) or edition (translators, editors ...)
            $table->string('role', 30); // author, translator, editor, illustrator, introduction
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['contributor_id', 'contributable_type', 'contributable_id', 'role'], 'contributions_unique');
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('category_work', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'work_id']);
        });

        Schema::create('tag_work', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->primary(['tag_id', 'work_id']);
        });

        Schema::create('collection_work', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['collection_id', 'work_id']);
        });

        // Localized catalog metadata, independent of interface and edition language.
        Schema::create('metadata_translations', function (Blueprint $table) {
            $table->id();
            $table->morphs('translatable');
            $table->string('language_tag', 35);
            $table->string('field', 40);
            $table->text('value');
            $table->timestamps();
            $table->unique(['translatable_type', 'translatable_id', 'language_tag', 'field'], 'metadata_translations_unique');
        });

        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // file, csv
            $table->foreignId('edition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('edition_file_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued')->index(); // queued, running, review, failed, completed, discarded
            $table->string('stage', 30)->default('quarantine'); // quarantine, validation, extraction, review
            $table->string('original_filename')->nullable();
            $table->string('quarantine_path')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('extracted_metadata')->nullable();
            $table->json('report')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 80)->index();
            $table->nullableMorphs('subject');
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('rights_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('edition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('rights'); // rights, contact, accessibility
            $table->string('name');
            $table->string('email');
            $table->text('message');
            $table->string('status', 20)->default('open'); // open, resolved
            $table->text('resolution')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'rights_reports', 'audit_events', 'import_jobs', 'metadata_translations',
            'collection_work', 'tag_work', 'category_work', 'collections', 'tags', 'categories',
            'contributions', 'edition_files', 'editions', 'work_relations', 'works', 'series',
            'contributors', 'languages',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
