<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per SSO ticket that has been presented (valid or not). The unique ticket id makes a
 * copied ticket useless: the second attempt cannot insert the same id. Rows are purged once the
 * ticket could no longer be valid anyway (php artisan sso:purge-tickets, scheduled daily).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_consumed_tickets', function (Blueprint $table) {
            $table->char('jti', 64)->primary();
            $table->unsignedInteger('cms_staff_id')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_consumed_tickets');
    }
};
