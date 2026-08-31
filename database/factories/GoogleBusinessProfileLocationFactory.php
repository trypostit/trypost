<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SocialAccount\Platform;
use App\Models\GoogleBusinessProfileLocation;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GoogleBusinessProfileLocation> */
class GoogleBusinessProfileLocationFactory extends Factory
{
    protected $model = GoogleBusinessProfileLocation::class;

    public function definition(): array
    {
        $locationId = fake()->unique()->numerify('###############');

        return [
            'social_account_id' => SocialAccount::factory()->state([
                'platform' => Platform::GoogleBusinessProfile,
                'scopes' => ['https://www.googleapis.com/auth/business.manage'],
            ]),
            'google_account_name' => 'accounts/'.fake()->numerify('##########'),
            'google_location_name' => 'locations/'.$locationId,
            'title' => fake()->company(),
            'store_code' => fake()->optional()->bothify('STORE-###'),
            'timezone' => fake()->timezone(),
            'maps_uri' => 'https://maps.google.com/?cid='.$locationId,
            'website_uri' => fake()->url(),
            'phone_number' => fake()->phoneNumber(),
            'storefront_address' => ['locality' => fake()->city(), 'regionCode' => 'US'],
            'metadata' => [],
            'is_selected' => true,
            'is_verified' => true,
            'last_synced_at' => now(),
        ];
    }
}
