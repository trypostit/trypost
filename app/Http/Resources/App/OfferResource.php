<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;

/**
 * The offer's copy lives in lang/<locale>/offers.php. The page has no
 * fallback language, so each key is checked in the current locale only and
 * the page never prints a raw key.
 *
 * @mixin Offer
 */
class OfferResource extends JsonResource
{
    /**
     * @return array{key: string, has_banner: bool, has_price: bool}
     */
    public function toArray(Request $request): array
    {
        $key = $this->langKey();

        return [
            'key' => $key,
            'has_banner' => $this->hasCopy("offers.{$key}.name") && $this->hasCopy("offers.{$key}.headline"),
            'has_price' => $this->hasCopy("offers.{$key}.price") && $this->hasCopy("offers.{$key}.period"),
        ];
    }

    private function hasCopy(string $key): bool
    {
        return Lang::has($key, null, false);
    }
}
