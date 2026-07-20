<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            // The rated item (e.g. a generated post). Optional: a standalone
            // rating on a result screen is still a valid data point.
            $table->nullableUuidMorphs('rateable');
            $table->unsignedTinyInteger('rating'); // 1..5
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            // One rating per rated item, enforced by the database and not just
            // the app. Standalone ratings (null morph) stay allowed, since NULLs
            // are distinct in a unique index.
            $table->unique(['workspace_id', 'rateable_type', 'rateable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_ratings');
    }
};
