<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL does not index a foreign key on its own, so the tree lookups
     * (parent_id, root_id), the prune cutoff and the publication link get
     * their own index on both engines.
     */
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->index('parent_id', 'inbox_messages_parent_id_index');
            $table->index('root_id', 'inbox_messages_root_id_index');
            $table->index('platform_created_at', 'inbox_messages_platform_created_at_index');
        });

        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->index('analytics_publication_id', 'inbox_conversations_analytics_publication_id_index');
        });
    }

    /**
     * MySQL refuses to drop the only index backing a foreign key, so each
     * foreign key is dropped around its index and added back.
     */
    public function down(): void
    {
        Schema::table('inbox_conversations', function (Blueprint $table) {
            $table->dropForeign(['analytics_publication_id']);
            $table->dropIndex('inbox_conversations_analytics_publication_id_index');
            $table->foreign('analytics_publication_id')->references('id')->on('analytics_publications')->nullOnDelete();
        });

        Schema::table('inbox_messages', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['root_id']);
            $table->dropIndex('inbox_messages_platform_created_at_index');
            $table->dropIndex('inbox_messages_root_id_index');
            $table->dropIndex('inbox_messages_parent_id_index');
            $table->foreign('parent_id')->references('id')->on('inbox_messages')->nullOnDelete();
            $table->foreign('root_id')->references('id')->on('inbox_messages')->nullOnDelete();
        });
    }
};
