<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_business_profile_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('google_account_name');
            $table->string('google_location_name');
            $table->string('title');
            $table->string('store_code')->nullable();
            $table->string('timezone')->nullable();
            $table->text('maps_uri')->nullable();
            $table->text('website_uri')->nullable();
            $table->string('phone_number')->nullable();
            $table->json('storefront_address')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'google_location_name'], 'gbp_locations_account_location_unique');
            $table->index(['social_account_id', 'is_selected']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_business_profile_locations');
    }
};
