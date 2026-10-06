<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Staff do not register or keep a separate password here: they arrive signed in from kmu-cms
 * (see docs/architecture/adr-0002-sso-from-cms.md). Each local user is linked to exactly one
 * CMS staff record, and a local password is only kept for break-glass administrator accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('cms_staff_id')->nullable()->unique()->after('id');
            $table->boolean('is_active')->default(true)->after('email');
            $table->string('password')->nullable()->change();
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['cms_staff_id']);
            $table->dropColumn(['cms_staff_id', 'is_active', 'last_login_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
