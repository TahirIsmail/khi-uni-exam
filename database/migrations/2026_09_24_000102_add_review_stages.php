<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * KMU reviews a question at two levels before it is approved (KMU QBank mechanism, "Who will use
 * the system?"):
 *
 *   subject   — the Department / Subject Reviewer checks the question in their subject;
 *   academic  — the QBank / Academic Reviewer (DME/DDE) then checks its quality and suitability.
 *
 * Only then does it reach the Approving Authority. Each assignment and each review says which level
 * it belongs to, so the queues, the approval gate and the history can tell them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qb_review_assignments', function (Blueprint $table) {
            $table->enum('stage', ['subject', 'academic'])->default('subject')->after('reviewer_id');
            $table->index(['version_id', 'stage', 'status']);
        });

        Schema::table('qb_reviews', function (Blueprint $table) {
            $table->enum('stage', ['subject', 'academic'])->default('subject')->after('reviewer_id');
        });
    }

    public function down(): void
    {
        Schema::table('qb_reviews', function (Blueprint $table) {
            $table->dropColumn('stage');
        });

        Schema::table('qb_review_assignments', function (Blueprint $table) {
            $table->dropIndex(['version_id', 'stage', 'status']);
            $table->dropColumn('stage');
        });
    }
};
