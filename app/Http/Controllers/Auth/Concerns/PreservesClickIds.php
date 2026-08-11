<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;

trait PreservesClickIds
{
    private const array CLICK_ID_KEYS = [
        'gclid',
        'fbclid',
        'li_fat_id',
        'ttclid',
        'rdt_cid',
        'epik',
    ];

    /**
     * @return array<string, string>
     */
    private function extractClickIds(Request $request): array
    {
        return array_filter(
            array_map(
                fn (string $value) => mb_substr($value, 0, 255),
                array_filter($request->only(self::CLICK_ID_KEYS), 'is_string'),
            ),
        );
    }

    private function storeClickIds(Request $request): void
    {
        $clickIds = $this->extractClickIds($request);

        if ($clickIds !== []) {
            $request->session()->put('click_ids', $clickIds);
        }
    }

    /**
     * @return array<string, string>
     */
    private function retrieveClickIds(): array
    {
        return session()->pull('click_ids', []);
    }
}
