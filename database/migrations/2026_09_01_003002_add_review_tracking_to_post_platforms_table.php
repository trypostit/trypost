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
            $table->timestamp('submitted_at')->nullable()->after('published_at');
            $table->timestamp('last_reconciled_at')->nullable()->after('submitted_at');

            $table->index(['status', 'last_reconciled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('post_platforms', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_reconciled_at']);
            $table->dropColumn(['submitted_at', 'last_reconciled_at']);
        });
    }
};
