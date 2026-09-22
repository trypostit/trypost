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
            $table->index(
                ['account_id', 'id'],
                'workspaces_account_publishing_index',
            );
        });

        Schema::table('post_platforms', function (Blueprint $table) {
            $table->timestamp('published_at', precision: 6)->nullable()->change();
            $table->index(
                ['post_id', 'status', 'published_at'],
                'post_platforms_publishing_lookup_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_platforms', function (Blueprint $table) {
            $table->dropIndex('post_platforms_publishing_lookup_index');
            $table->timestamp('published_at')->nullable()->change();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropIndex('workspaces_account_publishing_index');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }
};
