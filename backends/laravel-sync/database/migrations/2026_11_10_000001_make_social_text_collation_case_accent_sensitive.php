<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 32)->collation('utf8mb4_0900_as_cs')->change();
            $table->string('nickname', 100)->collation('utf8mb4_0900_as_cs')->change();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('content', 500)->collation('utf8mb4_0900_as_cs')->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 32)->collation('utf8mb4_unicode_ci')->change();
            $table->string('nickname', 100)->collation('utf8mb4_unicode_ci')->change();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('content', 500)->collation('utf8mb4_unicode_ci')->change();
        });
    }
};
