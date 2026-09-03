<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a publish mode (auto vs. notify-only) and the guard timestamp used to
     * send the manual-publish notification exactly once per post.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('publish_mode')->default('auto')->after('status');
            $table->timestamp('manual_publish_notified_at')->nullable()->after('publish_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['publish_mode', 'manual_publish_notified_at']);
        });
    }
};
