<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('post_notes')->whereNotNull('parent_id')->update(['parent_id' => null]);

        Schema::table('post_notes', function (Blueprint $table) {
            $table->dropForeign('post_comments_parent_id_foreign');
        });

        Schema::table('post_notes', function (Blueprint $table) {
            $table->dropColumn(['parent_id', 'reactions']);
        });
    }

    public function down(): void
    {
        Schema::table('post_notes', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->after('user_id');
            $table->json('reactions')->nullable()->after('body');
        });

        Schema::table('post_notes', function (Blueprint $table) {
            $table->foreign('parent_id', 'post_comments_parent_id_foreign')
                ->references('id')
                ->on('post_notes')
                ->cascadeOnDelete();
        });
    }
};
