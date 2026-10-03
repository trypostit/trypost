<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Services\Social\PinterestPublisher;

class CreatePinterestBoard
{
    /**
     * @param  array{name: string}  $data
     * @return array{id: string, name: string, cover_url: string|null}
     */
    public static function execute(SocialAccount $account, array $data): array
    {
        $board = app(PinterestPublisher::class)->createBoard($account, (string) data_get($data, 'name'));

        return ListPinterestBoards::present($board);
    }
}
