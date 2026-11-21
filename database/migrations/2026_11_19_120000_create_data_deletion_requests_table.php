<?php

declare(strict_types=1);

use App\Enums\DataDeletionRequest\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_deletion_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('app');
            $table->string('confirmation_code', 32)->unique();
            $table->string('status')->default(Status::DEFAULT->value);
            $table->unsignedInteger('channels_affected')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
    }
};
