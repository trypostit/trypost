<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\DataTransferObjects\MediaItem;
use App\Enums\SocialAccount\Platform;
use App\Enums\Workspace\ContentLanguage;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\GoogleBusinessPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Support\GoogleBusinessResourceName;
use App\Support\PostPlatformMetaRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GoogleBusinessPublisher
{
    /** Where the Google-shaped copies of post images live on the default disk. */
    private const DERIVATIVE_DIRECTORY = 'google-business-derivatives';

    use HasSocialHttpClient;

    private string $accountManagementUrl;

    private string $businessInformationUrl;

    private string $localPostsUrl;

    public function __construct()
    {
        $this->accountManagementUrl = config('trypost.platforms.google_business.account_management_api');
        $this->businessInformationUrl = config('trypost.platforms.google_business.business_information_api');
        $this->localPostsUrl = config('trypost.platforms.google_business.local_posts_api');
    }

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $account = $postPlatform->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $content = $postPlatform->post->content
            ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, Platform::GoogleBusiness)
            : '';

        $locationId = (string) data_get($account->meta, 'location_id');

        if (blank($locationId)) {
            throw new GoogleBusinessPublishException(
                userMessage: 'This Google Business Profile account has no location configured. Please reconnect it.',
                category: ErrorCategory::Permission,
            );
        }

        $payload = $this->buildPayload($postPlatform, $content);

        $response = $this->socialHttp()->withToken($account->access_token)
            ->post("{$this->localPostsUrl}/{$locationId}/localPosts", $payload);

        if ($response->failed()) {
            Log::error('Google Business Profile post creation failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $created = $response->json() ?? [];

        return [
            'id' => (string) data_get($created, 'name'),
            'url' => (string) (data_get($created, 'searchUrl') ?: GoogleBusinessResourceName::dashboardUrl($locationId)),
            'state' => (string) (data_get($created, 'state') ?: 'PROCESSING'),
        ];
    }

    /**
     * `id` is the full `accounts/{id}/locations/{id}` name the v4 Local Posts API
     * needs as its parent; `location_name` is the short `locations/{id}` name the
     * v1 Business Information and Performance APIs expect.
     *
     * @return list<array{id: string, account_name: string, location_name: string, title: string, address: ?string}>
     */
    public function fetchLocations(string $accessToken): array
    {
        $locations = [];

        foreach ($this->fetchAccounts($accessToken) as $accountName) {
            array_push($locations, ...$this->fetchLocationsForAccount($accessToken, $accountName));
        }

        return $locations;
    }

    /**
     * @return array<string, mixed> The Local Post request body.
     */
    private function buildPayload(PostPlatform $postPlatform, string $content): array
    {
        $languageCode = $postPlatform->post->workspace->content_language ?? ContentLanguage::DEFAULT->value;
        $topicType = (string) (data_get($postPlatform->meta, 'topic_type') ?? 'STANDARD');

        $payload = [
            'languageCode' => $languageCode,
            'summary' => $content,
            'topicType' => $topicType,
        ];

        $callToActionType = data_get($postPlatform->meta, 'call_to_action.action_type');

        if (filled($callToActionType) && $callToActionType !== 'NONE') {
            $callToAction = ['actionType' => $callToActionType];

            if ($callToActionType !== 'CALL') {
                $callToAction['url'] = data_get($postPlatform->meta, 'call_to_action.url');
            }

            $payload['callToAction'] = $callToAction;
        }

        $media = $postPlatform->post->mediaItems->first(fn ($item) => $item->isImage());

        if ($media) {
            $payload['media'] = [[
                'mediaFormat' => 'PHOTO',
                'sourceUrl' => $this->imageSourceUrl($media),
            ]];
        }

        if (in_array($topicType, PostPlatformMetaRules::GOOGLE_BUSINESS_EVENT_TOPIC_TYPES, true)) {
            $payload['event'] = $this->buildEvent($postPlatform);
        }

        if ($topicType === 'OFFER') {
            $offer = $this->buildOffer($postPlatform);

            if ($offer !== []) {
                $payload['offer'] = $offer;
            }
        }

        return $payload;
    }

    /**
     * Google fetches the image from the URL we hand it and rejects anything
     * outside its size and format rules, so it gets a JPEG derivative built to
     * the platform's MediaOptimizer profile rather than whatever the user
     * uploaded. The derivative lives beside the original on the default disk;
     * a retry rebuilds it rather than depending on one surviving.
     */
    private function imageSourceUrl(MediaItem $media): string
    {
        $input = tempnam(sys_get_temp_dir(), 'gbp_');

        if ($input === false || blank($media->path) || ! Storage::exists($media->path)) {
            return $media->url;
        }

        $optimized = null;

        try {
            file_put_contents($input, Storage::get($media->path));
            $optimized = app(MediaOptimizer::class)->optimizeImage($input, Platform::GoogleBusiness);

            $derivativePath = self::DERIVATIVE_DIRECTORY.'/'.Str::uuid()->toString().'.jpg';
            Storage::put($derivativePath, file_get_contents($optimized));

            return Storage::url($derivativePath);
        } catch (Throwable $e) {
            Log::warning('Google Business Profile image derivative failed; sending the original', [
                'path' => $media->path,
                'error' => $e->getMessage(),
            ]);

            return $media->url;
        } finally {
            @unlink($input);

            if ($optimized !== null) {
                @unlink($optimized);
            }
        }
    }

    /**
     * The v4 Local Posts API requires `event` for both the EVENT and OFFER topic
     * types, so both read the same `meta.event.*` fields.
     */
    private function buildEvent(PostPlatform $postPlatform): array
    {
        $title = (string) data_get($postPlatform->meta, 'event.title');

        if (blank($title)) {
            throw new GoogleBusinessPublishException(
                userMessage: 'This Google Business Profile post needs an event title. Please add one and try again.',
                category: ErrorCategory::ContentPolicy,
            );
        }

        $startDate = (string) data_get($postPlatform->meta, 'event.start_date');
        $endDate = (string) data_get($postPlatform->meta, 'event.end_date');

        if (blank($startDate) || blank($endDate)) {
            throw new GoogleBusinessPublishException(
                userMessage: 'This Google Business Profile post needs an event start and end date. Please add them and try again.',
                category: ErrorCategory::ContentPolicy,
            );
        }

        $schedule = [
            'startDate' => $this->formatDate($startDate),
            'endDate' => $this->formatDate($endDate),
        ];

        if (filled(data_get($postPlatform->meta, 'event.start_time'))) {
            $schedule['startTime'] = $this->formatTime((string) data_get($postPlatform->meta, 'event.start_time'));
        }

        if (filled(data_get($postPlatform->meta, 'event.end_time'))) {
            $schedule['endTime'] = $this->formatTime((string) data_get($postPlatform->meta, 'event.end_time'));
        }

        return [
            'title' => $title,
            'schedule' => $schedule,
        ];
    }

    private function buildOffer(PostPlatform $postPlatform): array
    {
        return array_filter([
            'couponCode' => data_get($postPlatform->meta, 'offer.coupon_code'),
            'redeemOnlineUrl' => data_get($postPlatform->meta, 'offer.redeem_online_url'),
            'termsConditions' => data_get($postPlatform->meta, 'offer.terms_conditions'),
        ], fn ($value) => filled($value));
    }

    /**
     * @return array{year: int, month: int, day: int}
     */
    private function formatDate(string $date): array
    {
        $carbon = CarbonImmutable::parse($date);

        return ['year' => (int) $carbon->format('Y'), 'month' => (int) $carbon->format('n'), 'day' => (int) $carbon->format('j')];
    }

    /**
     * @return array{hours: int, minutes: int, seconds: int, nanos: int}
     */
    private function formatTime(string $time): array
    {
        $carbon = CarbonImmutable::parse($time);

        return ['hours' => (int) $carbon->format('G'), 'minutes' => (int) $carbon->format('i'), 'seconds' => 0, 'nanos' => 0];
    }

    /**
     * Re-read a Local Post so its review state can be settled. Google answers a
     * create long before the post clears moderation, so `state` is the only
     * place that says whether it went live or was refused.
     *
     * @return array<string, mixed>
     */
    public function fetchLocalPost(SocialAccount $account, string $localPostName): array
    {
        $response = $this->socialHttp()->withToken($account->access_token)
            ->get("{$this->localPostsUrl}/{$localPostName}");

        if ($response->failed()) {
            Log::warning('Google Business Profile post lookup failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        return $response->json() ?? [];
    }

    /**
     * @return list<string> Full "accounts/{id}" resource names.
     */
    private function fetchAccounts(string $accessToken): array
    {
        $accounts = [];
        $pageToken = null;

        do {
            $response = $this->socialHttp()->withToken($accessToken)
                ->get("{$this->accountManagementUrl}/accounts", array_filter([
                    // The Account Management API caps this at 20; asking for
                    // more is silently clamped and hides the real page size.
                    'pageSize' => 20,
                    'pageToken' => $pageToken,
                ]));

            if ($response->failed()) {
                Log::error('Google Business Profile accounts fetch failed', [
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);
                $this->handleApiError($response);
            }

            $data = $response->json() ?? [];

            foreach (data_get($data, 'accounts', []) as $account) {
                $accounts[] = (string) data_get($account, 'name');
            }

            $pageToken = data_get($data, 'nextPageToken');
        } while (filled($pageToken));

        return $accounts;
    }

    /**
     * @return list<array{id: string, account_name: string, location_name: string, title: string, address: ?string}>
     */
    private function fetchLocationsForAccount(string $accessToken, string $accountName): array
    {
        $locations = [];
        $pageToken = null;

        do {
            $response = $this->socialHttp()->withToken($accessToken)
                ->get("{$this->businessInformationUrl}/{$accountName}/locations", array_filter([
                    'readMask' => 'name,title,storefrontAddress,metadata',
                    'pageSize' => 100,
                    'pageToken' => $pageToken,
                ]));

            if ($response->failed()) {
                Log::error('Google Business Profile locations fetch failed', [
                    'account' => $accountName,
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);
                $this->handleApiError($response);
            }

            $data = $response->json() ?? [];

            foreach (data_get($data, 'locations', []) as $location) {
                // Google tells us which listings can carry a Local Post at all.
                // Only an explicit refusal disqualifies one — an absent flag is
                // not a no, and dropping those would hide working locations.
                if (data_get($location, 'metadata.canOperateLocalPost') === false) {
                    continue;
                }

                $shortName = (string) data_get($location, 'name');

                $locations[] = [
                    'id' => GoogleBusinessResourceName::toFullLocationName($accountName, $shortName),
                    'account_name' => $accountName,
                    'location_name' => $shortName,
                    'title' => (string) data_get($location, 'title'),
                    'address' => $this->formatAddress(data_get($location, 'storefrontAddress')),
                ];
            }

            $pageToken = data_get($data, 'nextPageToken');
        } while (filled($pageToken));

        return $locations;
    }

    private function formatAddress(?array $storefrontAddress): ?string
    {
        if (! $storefrontAddress) {
            return null;
        }

        $lines = (array) data_get($storefrontAddress, 'addressLines', []);
        $locality = data_get($storefrontAddress, 'locality');
        $parts = array_filter([implode(' ', $lines), $locality]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function handleApiError(Response $response): never
    {
        throw GoogleBusinessPublishException::fromApiResponse($response);
    }
}
