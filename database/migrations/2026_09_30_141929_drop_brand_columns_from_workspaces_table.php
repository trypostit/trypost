<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn([
                'brand_website', 'brand_description', 'brand_voice_traits',
                'brand_color', 'background_color', 'text_color',
                'brand_font', 'image_style', 'content_language',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('brand_website')->nullable();
            $table->text('brand_description')->nullable();
            $table->json('brand_voice_traits')->nullable();
            $table->string('brand_color', 9)->nullable();
            $table->string('background_color', 9)->nullable();
            $table->string('text_color', 9)->nullable();
            $table->string('brand_font')->default('Inter');
            $table->string('image_style')->default('cinematic');
            $table->string('content_language', 10)->default('en');
        });
    }
};
