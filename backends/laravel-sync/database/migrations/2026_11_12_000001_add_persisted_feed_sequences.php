<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('feed_seq')->default(0);
        });

        Schema::table('feed_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('seq')->default(0);
            $table->index(['user_id', 'seq', 'post_id'], 'feed_items_user_seq_post_idx');
        });
    }

    public function down(): void
    {
        Schema::table('feed_items', function (Blueprint $table): void {
            $table->dropIndex('feed_items_user_seq_post_idx');
            $table->dropColumn('seq');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('feed_seq');
        });
    }
};
