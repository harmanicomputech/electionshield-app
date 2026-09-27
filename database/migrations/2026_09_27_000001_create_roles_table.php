<?php

use App\Support\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Editable roles (users.role holds the role's key), and agent accounts:
 * agents sign in with their phone number, so email becomes optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->json('permissions');
            $table->boolean('system')->default(false);
            $table->timestamps();
        });

        foreach (Permission::defaultRoles() as $key => $role) {
            DB::table('roles')->insert([
                'key' => $key,
                'name' => $role['name'],
                'description' => $role['description'],
                'permissions' => json_encode($role['permissions']),
                'system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 40)->default('coordinator')->change();
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn('phone');
        });

        Schema::dropIfExists('roles');
    }
};
