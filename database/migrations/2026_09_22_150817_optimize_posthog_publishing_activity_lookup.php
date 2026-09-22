<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->index(['account_id', 'id']);
        });

        Schema::table('post_platforms', function (Blueprint $table) {
            $table->index(['post_id', 'status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_platforms', function (Blueprint $table) {
            $table->dropIndex(['post_id', 'status', 'published_at']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'id']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }
};
