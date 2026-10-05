<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('reader')->index(); // reader, editor, admin
            $table->string('locale', 12)->nullable();
            $table->json('reader_preferences')->nullable();
            $table->unsignedBigInteger('preferences_revision')->default(0);
        });

        // Reading state always points at the exact file version that was read.
        Schema::create('reading_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_file_id')->constrained()->cascadeOnDelete();
            $table->string('locator_type', 12); // cfi, page, anchor
            $table->text('locator');
            $table->decimal('fraction', 6, 5)->nullable(); // position within this file only
            $table->string('label')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('device_id', 64)->nullable();
            $table->timestamp('client_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'edition_file_id']);
        });

        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_file_id')->constrained()->cascadeOnDelete();
            $table->string('locator_type', 12);
            $table->text('locator');
            $table->string('label')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('client_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['user_id', 'uuid']);
        });

        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_file_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12)->default('highlight'); // highlight, note
            $table->string('locator_type', 12);
            $table->text('locator');
            $table->text('quote')->nullable();
            $table->text('note')->nullable();
            $table->string('color', 20)->default('yellow');
            $table->unsignedBigInteger('revision')->default(1);
            $table->uuid('conflict_of')->nullable(); // preserved copy of a conflicting edit
            $table->timestamp('client_updated_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['user_id', 'uuid']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'work_id']);
        });

        Schema::create('reading_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('reading_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reading_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['reading_list_id', 'edition_id']);
        });
    }

    public function down(): void
    {
        foreach (['reading_list_items', 'reading_lists', 'favorites', 'annotations', 'bookmarks', 'reading_progress'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'locale', 'reader_preferences', 'preferences_revision']);
        });
    }
};
