<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_note_reactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_note_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $emoji = $table->string('emoji', 64);
            $table->timestamps();

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $emoji->collation('utf8mb4_bin');
            }

            $table->unique(['post_note_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_note_reactions');
    }
};
