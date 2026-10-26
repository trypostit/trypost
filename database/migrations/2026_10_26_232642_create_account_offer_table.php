<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_offer', function (Blueprint $table): void {
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('offer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamp('redeemed_at')->nullable();

            $table->primary(['account_id', 'offer_id']);
            $table->index(['offer_id', 'redeemed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_offer');
    }
};
