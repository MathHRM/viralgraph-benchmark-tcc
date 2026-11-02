<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $username = $table->string('username')->nullable();
                if (Schema::getConnection()->getDriverName() === 'sqlite') {
                    $username->collation('NOCASE');
                }
            });

            DB::table('users')->whereNull('username')->orderBy('id')->eachById(
                fn (object $user) => DB::table('users')->where('id', $user->id)->update([
                    'username' => 'user-'.$user->id,
                ]),
            );

            Schema::table('users', function (Blueprint $table) {
                $table->string('username')->nullable(false)->change();
                $table->unique('username');
            });
        }

        foreach (['name', 'email', 'password'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn (Blueprint $table) => $table->string($column)->nullable()->change());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
