<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_source_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('external_user_id')->nullable();
            $table->string('external_team_id')->nullable();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_source_connections');
    }
};
