<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('origin', 20);
            $table->foreignUuid('event_author_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('event_id');

            $table->unique(['user_id', 'post_id']);
            $table->index('post_id');
            $table->index('event_author_id');
        });

        DB::statement('CREATE INDEX feed_items_user_event_id_desc ON feed_items (user_id, event_id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_items');
    }
};
