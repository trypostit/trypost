<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_platforms', function (Blueprint $table) {
            $table->timestamp('connection_warning_sent_at')->nullable()->after('error_context');
            $table->index(['status', 'enabled', 'connection_warning_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('post_platforms', function (Blueprint $table) {
            $table->dropIndex(['status', 'enabled', 'connection_warning_sent_at']);
            $table->dropColumn('connection_warning_sent_at');
        });
    }
};
