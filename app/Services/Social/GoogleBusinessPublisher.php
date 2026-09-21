<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
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
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GoogleBusinessPublisher
{
    /** Where the Google-shaped copies of post images live on the default disk. */
    public const string DERIVATIVE_DIRECTORY = GoogleBusinessDerivativeCleaner::DIRECTORY;

    use HasSocialHttpClient;

    private ?string $derivativePath = null;

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
        $this->derivativePath = null;
        $keepDerivative = false;

        try {
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
                    userMessage: __('posts.errors.google_business.no_location'),
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
            $state = (string) (data_get($created, 'state') ?: 'PROCESSING');
            // Google fetches sourceUrl after create while the post is still
            // PROCESSING / SCHEDULED. Deleting here races PHOTO_FETCH_FAILED.
            $keepDerivative = in_array($state, ['PROCESSING', 'SCHEDULED'], true);

            return [
                'id' => (string) data_get($created, 'name'),
                'url' => (string) (data_get($created, 'searchUrl') ?: GoogleBusinessResourceName::dashboardUrl($locationId)),
                'state' => $state,
            ];
        } finally {
            if (! $keepDerivative) {
                $this->forgetDerivative();
            }
        }
    }

    /**
     * `id` is the full `accounts/{id}/locations/{id}` name the v4 Local Posts API
     * needs as its parent; `location_name` is the short `locations/{id}` name the
     * v1 Business Information and Performance APIs expect.
     *
     * @return list<array{id: string, account_name: string, location_name: string, title: string, address: ?string, maps_uri: ?string}>
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
     * Profile photos live at the fixed `/media/profile` resource — not in a
     * full media list, which the OAuth callback must not walk per location.
     *
     * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.media/get
     */
    public function fetchLocationPhoto(string $accessToken, string $fullLocationName): ?string
    {
        $response = $this->socialHttp()->withToken($accessToken)
            ->get("{$this->localPostsUrl}/{$fullLocationName}/media/profile");

        if ($response->failed()) {
            Log::warning('Google Business Profile location profile photo fetch failed', [
                'location' => $fullLocationName,
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        $url = data_get($response->json(), 'thumbnailUrl') ?: data_get($response->json(), 'googleUrl');

        return filled($url) ? (string) $url : null;
    }

    /**
     * @return array<string, mixed> The Local Post request body.
     */
    private function buildPayload(PostPlatform $postPlatform, string $content): array
    {
        $language = ContentLanguage::tryFrom((string) ($postPlatform->post->workspace->content_language ?? ContentLanguage::DEFAULT->value))
            ?? ContentLanguage::DEFAULT;
        $topicType = (string) (data_get($postPlatform->meta, 'topic_type') ?? 'STANDARD');

        $payload = [
            'languageCode' => $language->bcp47(),
            'summary' => $content,
            'topicType' => $topicType,
        ];

        $callToActionType = data_get($postPlatform->meta, 'call_to_action.action_type');

        // Google ignores callToAction on OFFER posts.
        if ($topicType !== 'OFFER' && filled($callToActionType) && $callToActionType !== 'NONE') {
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
                'sourceUrl' => $this->imageSourceUrl($media, $postPlatform->id),
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
    private function imageSourceUrl(MediaItem $media, string $postPlatformId): string
    {
        $input = tempnam(sys_get_temp_dir(), 'gbp_');

        if ($input === false || blank($media->path) || ! Storage::exists($media->path)) {
            return $media->url;
        }

        $optimized = null;

        try {
            file_put_contents($input, Storage::get($media->path));
            $optimized = app(MediaOptimizer::class)->optimizeImage($input, Platform::GoogleBusiness);

            $this->derivativePath = GoogleBusinessDerivativeCleaner::pathFor($postPlatformId);
            Storage::put($this->derivativePath, file_get_contents($optimized));

            return Storage::url($this->derivativePath);
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
        $topicType = (string) (data_get($postPlatform->meta, 'topic_type') ?? 'STANDARD');

        if (blank($title)) {
            throw new GoogleBusinessPublishException(
                userMessage: $topicType === 'OFFER'
                    ? __('posts.form.google_business.offer_title_required')
                    : __('posts.form.google_business.event_title_required'),
                category: ErrorCategory::ContentPolicy,
            );
        }

        $startDate = (string) data_get($postPlatform->meta, 'event.start_date');
        $endDate = (string) data_get($postPlatform->meta, 'event.end_date');

        if (blank($startDate) || blank($endDate)) {
            throw new GoogleBusinessPublishException(
                userMessage: __('posts.errors.google_business.event_dates_required'),
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
     * @return list<array{id: string, account_name: string, location_name: string, title: string, address: ?string, maps_uri: ?string}>
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
                    'maps_uri' => filled(data_get($location, 'metadata.mapsUri'))
                        ? (string) data_get($location, 'metadata.mapsUri')
                        : null,
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

    private function forgetDerivative(): void
    {
        if ($this->derivativePath === null) {
            return;
        }

        try {
            Storage::delete($this->derivativePath);
        } catch (Throwable $e) {
            Log::warning('Failed to prune Google Business Profile image derivative', [
                'path' => $this->derivativePath,
                'error' => $e->getMessage(),
            ]);
        }

        $this->derivativePath = null;
    }
}
