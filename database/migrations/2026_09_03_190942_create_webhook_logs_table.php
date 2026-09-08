<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_id')->constrained('webhooks')->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index('webhook_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
