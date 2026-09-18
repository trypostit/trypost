<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_platforms', function (Blueprint $table): void {
            $table->index('platform_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('post_platforms', function (Blueprint $table): void {
            $table->dropIndex(['platform_post_id']);
        });
    }
};
