<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Access control (blueprint section 6):
 * - sec_permissions: the catalogue (App\Domain\Identity\Authorization\Permissions);
 * - sec_role_permissions: permissions granted to kmu-cms roles (roles live in the CMS);
 * - sec_user_scopes: where a user may act (everywhere, or specific programmes, professionals, courses);
 * - users.is_break_glass: local emergency administrator accounts (no CMS role), set only by command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_break_glass')->default(false)->after('is_active');
        });

        Schema::create('sec_permissions', function (Blueprint $table) {
            $table->string('code', 60)->primary();
            $table->string('group_name', 60);
            $table->string('description', 150);
            $table->boolean('is_privileged')->default(false);
            $table->timestamps();
        });

        Schema::create('sec_role_permissions', function (Blueprint $table) {
            $table->unsignedInteger('cms_role_id')->comment('kmu-cms roles.id');
            $table->string('permission_code', 60);
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->primary(['cms_role_id', 'permission_code']);
            $table->foreign('permission_code')->references('code')->on('sec_permissions')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('sec_user_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('scope_type', ['all', 'programme', 'professional', 'course']);
            $table->unsignedInteger('scope_id')->nullable()->comment('kmu-cms id; null for all');
            $table->unsignedInteger('scope_key')->storedAs('IFNULL(scope_id, 0)')->comment('lets the unique key cover scope_type all');
            $table->foreignId('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'scope_type', 'scope_key']);
        });

        // (The permission catalogue was seeded here until administration moved to kmu-cms; see 000104.)

        // v_cms_roles was added to the CMS views.
        foreach (CmsViews::definitions((string) config('database.cms_source_database')) as $name => $select) {
            DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `{$name}` AS {$select}");
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_cms_roles`');
        Schema::dropIfExists('sec_user_scopes');
        Schema::dropIfExists('sec_role_permissions');
        Schema::dropIfExists('sec_permissions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_break_glass');
        });
    }
};
