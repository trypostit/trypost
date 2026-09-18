<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Status;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LinkedInAnalytics
{
    use HasSocialHttpClient;

    /**
     * Personal-profile likes/comments live on the unversioned
     * `/v2/socialActions/{shareUrn}` edge. `/rest/socialActions` and
     * `memberCreatorPostAnalytics` both 403 with a member token
     * (`ACCESS_DENIED` / missing `r_member_postAnalytics`).
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
     *
     * @return array<int, array{label: string, value: int}>|array{unsupported: true, reason: string}
     */
    public function fetchPostMetrics(PostPlatform $postPlatform): array
    {
        if (! $postPlatform->platform_post_id) {
            return ['unsupported' => true, 'reason' => 'missing_post_id'];
        }

        $bound = $postPlatform->socialAccount;
        $accounts = $bound ? collect([$bound]) : $this->connectedWorkspaceAccounts($postPlatform);

        if ($accounts->isEmpty()) {
            return ['unsupported' => true, 'reason' => 'missing_post_id'];
        }

        foreach ($accounts as $account) {
            $metrics = $this->metricsFrom($account, $postPlatform);

            if ($metrics !== null) {
                return $metrics;
            }

            if ($bound) {
                return ['unsupported' => true, 'reason' => 'api_error'];
            }
        }

        return ['unsupported' => true, 'reason' => 'api_error'];
    }

    /**
     * Disconnect deletes the social account and nulls `social_account_id`.
     * A workspace may have several member LinkedIns, so try each connected
     * token until one can read this URN.
     *
     * @return Collection<int, SocialAccount>
     */
    private function connectedWorkspaceAccounts(PostPlatform $postPlatform): Collection
    {
        $workspaceId = $postPlatform->post?->workspace_id;

        if (! $workspaceId) {
            return collect();
        }

        return SocialAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('platform', $postPlatform->platform)
            ->where('status', Status::Connected)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array<int, array{label: string, value: int}>|null
     */
    private function metricsFrom(SocialAccount $account, PostPlatform $postPlatform): ?array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
            $account->refresh();
        }

        $shareUrn = urlencode($postPlatform->platform_post_id);
        $baseUrl = config('trypost.platforms.linkedin.api');

        $response = $this->socialHttp()
            ->withToken($account->access_token)
            ->get("{$baseUrl}/v2/socialActions/{$shareUrn}");

        if ($response->failed()) {
            Log::warning('LinkedIn post metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        $data = $response->json();

        return [
            ['label' => __('analytics.metrics.likes'), 'value' => (int) data_get($data, 'likesSummary.totalLikes', 0)],
            ['label' => __('analytics.metrics.comments'), 'value' => (int) data_get($data, 'commentsSummary.aggregatedTotalComments', 0)],
        ];
    }
}
