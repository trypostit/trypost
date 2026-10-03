<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Services\Social\PinterestPublisher;
use Illuminate\Support\Collection;

class ListPinterestBoards
{
    /**
     * @return array{boards: list<array{id: string, name: string, cover_url: string|null}>, truncated: bool}
     */
    public static function execute(SocialAccount $account): array
    {
        $result = app(PinterestPublisher::class)->getBoards($account);

        $boards = Collection::make(data_get($result, 'boards', []))
            ->map(fn (mixed $board): array => self::present($board))
            ->filter(fn (array $board): bool => $board['id'] !== '')
            ->values()
            ->all();

        return [
            'boards' => $boards,
            'truncated' => (bool) data_get($result, 'truncated', false),
        ];
    }

    /**
     * The board fields the app reads from a Pinterest API v5 board object.
     *
     * @return array{id: string, name: string, cover_url: string|null}
     */
    public static function present(mixed $board): array
    {
        $coverUrl = data_get($board, 'media.image_cover_url');

        return [
            'id' => (string) data_get($board, 'id'),
            'name' => (string) data_get($board, 'name'),
            'cover_url' => filled($coverUrl) ? (string) $coverUrl : null,
        ];
    }
}
