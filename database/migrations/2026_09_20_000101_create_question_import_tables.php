<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Importing questions from a spreadsheet (blueprint 13). A file is uploaded, every row is read and
 * checked, and nothing enters the bank until someone commits the import — so an author can see
 * exactly what would be created, and what is wrong, before it happens.
 *
 * The rows keep what was read (raw) and what it became (parsed), so a committed import can always
 * be traced back to the line in the file it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qb_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id')->comment('the campus the questions will belong to');
            $table->string('original_name', 255);
            $table->string('disk', 30)->default('local');
            $table->string('path', 255);
            $table->string('format', 10)->comment('csv, xlsx');
            $table->unsignedInteger('size_bytes');
            $table->json('defaults')->nullable()->comment('course, topic and type used when a row leaves them out');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->unsignedInteger('rows_committed')->default(0);
            $table->enum('status', ['checked', 'committed', 'discarded'])->default('checked');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('committed_at', 3)->nullable();
            $table->unsignedBigInteger('committed_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('qb_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('qb_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number')->comment('the line in the file, so a problem can be found there');
            $table->json('raw')->comment('the row as it was read');
            $table->json('parsed')->nullable()->comment('what it became: type, place, options, answers');
            $table->json('errors')->nullable()->comment('why this row cannot be imported');
            $table->json('warnings')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->enum('status', ['valid', 'invalid', 'duplicate', 'committed'])->default('valid');
            $table->unsignedBigInteger('question_id')->nullable();
            $table->unsignedBigInteger('version_id')->nullable();
            $table->timestamps();

            $table->unique(['import_id', 'row_number']);
            $table->index(['import_id', 'status']);
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qb_import_rows');
        Schema::dropIfExists('qb_imports');
    }
};
