<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table): void {
            // Quem entregou o post a quem compartilhou: autor (nível 1) ou outro compartilhador.
            $table->foreignUuid('source_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Passo recursivo da árvore de propagação: filhos de um nó dentro do mesmo post.
            $table->index(['post_id', 'source_user_id'], 'shares_post_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table): void {
            $table->dropIndex('shares_post_source_idx');
            $table->dropConstrainedForeignId('source_user_id');
        });
    }
};
