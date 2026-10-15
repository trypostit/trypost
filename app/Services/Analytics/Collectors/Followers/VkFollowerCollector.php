<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use App\Services\Social\Vk\VkApi;
use App\Support\Analytics\VkAnalyticsResponse;
use Carbon\CarbonImmutable;

class VkFollowerCollector extends AbstractFollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $ownerId = VkAnalyticsResponse::ownerId($account);

        if ($ownerId < 0) {
            $data = VkAnalyticsResponse::data($this->get($account, VkApi::endpoint('groups.getById'), [
                'group_ids' => abs($ownerId),
                'fields' => 'members_count',
                'v' => VkApi::version(),
            ]), 'follower collection');

            // v5.199 returns response.groups[], older versions response[].
            $group = data_get($data, 'groups.0') ?? data_get($data, '0');

            return $this->observation($date, data_get($group, 'members_count'));
        }

        $data = VkAnalyticsResponse::data($this->get($account, VkApi::endpoint('users.get'), [
            'user_ids' => $ownerId,
            'fields' => 'followers_count',
            'v' => VkApi::version(),
        ]), 'follower collection');

        return $this->observation($date, data_get($data, '0.followers_count'));
    }
}
