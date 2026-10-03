<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idea_workspace_label', function (Blueprint $table) {
            $table->foreignUuid('idea_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_label_id')->constrained()->cascadeOnDelete();

            $table->primary(['idea_id', 'workspace_label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idea_workspace_label');
    }
};
