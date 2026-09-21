<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
use App\Enums\GoogleBusiness\CtaAction;
use App\Enums\GoogleBusiness\LocalPostState;
use App\Enums\GoogleBusiness\TopicType;
use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Enums\Workspace\ContentLanguage;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\GoogleBusinessPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Support\GoogleBusinessResourceName;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GoogleBusinessPublisher
{
    use HasSocialHttpClient;

    public const string DERIVATIVE_DIRECTORY = GoogleBusinessDerivativeCleaner::DIRECTORY;

    private ?string $derivativePath = null;

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

            $locationId = $this->locationId($account);
            $created = $this->request(
                $account->access_token,
                'post',
                "{$this->url('local_posts_api')}/{$locationId}/localPosts",
                $this->buildPayload($postPlatform),
                'Google Business Profile post creation failed',
            );
            $state = LocalPostState::fromApi(data_get($created, 'state'));
            // Google fetches sourceUrl after create while the post is still
            // PROCESSING / SCHEDULED. Deleting here races PHOTO_FETCH_FAILED.
            $keepDerivative = $state->isPendingReview();

            return [
                'id' => (string) data_get($created, 'name'),
                'url' => (string) (data_get($created, 'searchUrl') ?: GoogleBusinessResourceName::dashboardUrl($locationId)),
                'state' => $state->value,
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
        return collect($this->fetchAccounts($accessToken))
            ->flatMap(fn (string $accountName) => $this->fetchLocationsForAccount($accessToken, $accountName))
            ->values()
            ->all();
    }

    /**
     * Profile photos live at `/media/profile` — do not walk the full media list.
     *
     * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.media/get
     */
    public function fetchLocationPhoto(string $accessToken, string $fullLocationName): ?string
    {
        $response = $this->socialHttp()->withToken($accessToken)
            ->get("{$this->url('local_posts_api')}/{$fullLocationName}/media/profile");

        if ($response->failed()) {
            Log::warning('Google Business Profile location profile photo fetch failed', [
                'location' => $fullLocationName,
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        $payload = $response->json();
        $url = data_get($payload, 'thumbnailUrl') ?: data_get($payload, 'googleUrl');

        return filled($url) ? (string) $url : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchLocalPost(SocialAccount $account, string $localPostName): array
    {
        return $this->request(
            $account->access_token,
            'get',
            "{$this->url('local_posts_api')}/{$localPostName}",
            [],
            'Google Business Profile post lookup failed',
            level: 'warning',
        );
    }

    private function locationId(SocialAccount $account): string
    {
        $locationId = (string) data_get($account->meta, 'location_id');

        if (blank($locationId)) {
            throw new GoogleBusinessPublishException(
                userMessage: __('posts.errors.google_business.no_location'),
                category: ErrorCategory::Permission,
            );
        }

        return $locationId;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(PostPlatform $postPlatform): array
    {
        $language = ContentLanguage::tryFrom((string) ($postPlatform->post->workspace->content_language ?? ContentLanguage::DEFAULT->value))
            ?? ContentLanguage::DEFAULT;
        $topicType = TopicType::fromMeta(data_get($postPlatform->meta, 'topic_type'));
        $content = $postPlatform->post->content
            ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, Platform::GoogleBusiness)
            : '';

        $payload = [
            'languageCode' => $language->bcp47(),
            'summary' => $content,
            'topicType' => $topicType->value,
        ];

        $callToAction = CtaAction::fromMeta(data_get($postPlatform->meta, 'call_to_action.action_type'));

        if ($topicType->allowsCallToAction() && $callToAction !== CtaAction::None) {
            $payload['callToAction'] = [
                'actionType' => $callToAction->value,
                ...($callToAction->requiresUrl() ? [
                    'url' => data_get($postPlatform->meta, 'call_to_action.url'),
                ] : []),
            ];
        }

        $media = $postPlatform->post->mediaItems->first(
            fn (MediaItem $item): bool => $item->isImage() && ! MediaType::isGif($item->mime_type),
        );

        if ($media) {
            $payload['media'] = [[
                'mediaFormat' => 'PHOTO',
                'sourceUrl' => $this->imageSourceUrl($media, $postPlatform->id),
            ]];
        }

        if ($topicType->requiresEvent()) {
            $payload['event'] = $this->buildEvent($postPlatform, $topicType);
        }

        $offer = $topicType === TopicType::Offer ? $this->buildOffer($postPlatform) : [];

        if ($offer !== []) {
            $payload['offer'] = $offer;
        }

        return $payload;
    }

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
     * @return array<string, mixed>
     */
    private function buildEvent(PostPlatform $postPlatform, TopicType $topicType): array
    {
        $title = $this->requiredMeta(
            $postPlatform,
            'event.title',
            $topicType === TopicType::Offer
                ? __('posts.form.google_business.offer_title_required')
                : __('posts.form.google_business.event_title_required'),
        );
        $startDate = $this->requiredMeta($postPlatform, 'event.start_date', __('posts.errors.google_business.event_dates_required'));
        $endDate = $this->requiredMeta($postPlatform, 'event.end_date', __('posts.errors.google_business.event_dates_required'));

        $schedule = [
            'startDate' => $this->dateParts($startDate),
            'endDate' => $this->dateParts($endDate),
        ];

        foreach (['start' => 'startTime', 'end' => 'endTime'] as $meta => $field) {
            $time = data_get($postPlatform->meta, "event.{$meta}_time");

            if (filled($time)) {
                $schedule[$field] = $this->timeParts((string) $time);
            }
        }

        return [
            'title' => $title,
            'schedule' => $schedule,
        ];
    }

    private function requiredMeta(PostPlatform $postPlatform, string $key, string $message): string
    {
        $value = (string) data_get($postPlatform->meta, $key);

        if (blank($value)) {
            throw new GoogleBusinessPublishException(
                userMessage: $message,
                category: ErrorCategory::ContentPolicy,
            );
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function buildOffer(PostPlatform $postPlatform): array
    {
        return array_filter([
            'couponCode' => data_get($postPlatform->meta, 'offer.coupon_code'),
            'redeemOnlineUrl' => data_get($postPlatform->meta, 'offer.redeem_online_url'),
            'termsConditions' => data_get($postPlatform->meta, 'offer.terms_conditions'),
        ], filled(...));
    }

    /**
     * @return array{year: int, month: int, day: int}
     */
    private function dateParts(string $date): array
    {
        $carbon = CarbonImmutable::parse($date);

        return ['year' => $carbon->year, 'month' => $carbon->month, 'day' => $carbon->day];
    }

    /**
     * @return array{hours: int, minutes: int, seconds: int, nanos: int}
     */
    private function timeParts(string $time): array
    {
        $carbon = CarbonImmutable::parse($time);

        return ['hours' => $carbon->hour, 'minutes' => $carbon->minute, 'seconds' => 0, 'nanos' => 0];
    }

    /**
     * @return list<string>
     */
    private function fetchAccounts(string $accessToken): array
    {
        return $this->pages(
            $accessToken,
            "{$this->url('account_management_api')}/accounts",
            // The Account Management API caps this at 20; asking for more is
            // silently clamped and hides the real page size.
            ['pageSize' => 20],
            'Google Business Profile accounts fetch failed',
            fn (array $data): array => collect(data_get($data, 'accounts', []))
                ->map(fn (array $account): string => (string) data_get($account, 'name'))
                ->all(),
        );
    }

    /**
     * @return list<array{id: string, account_name: string, location_name: string, title: string, address: ?string, maps_uri: ?string}>
     */
    private function fetchLocationsForAccount(string $accessToken, string $accountName): array
    {
        return $this->pages(
            $accessToken,
            "{$this->url('business_information_api')}/{$accountName}/locations",
            [
                'readMask' => 'name,title,storefrontAddress,metadata',
                'pageSize' => 100,
            ],
            'Google Business Profile locations fetch failed',
            fn (array $data): array => collect(data_get($data, 'locations', []))
                ->reject(fn (array $location): bool => data_get($location, 'metadata.canOperateLocalPost') === false)
                ->map(fn (array $location): array => $this->mapLocation($accountName, $location))
                ->values()
                ->all(),
            ['account' => $accountName],
        );
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array{id: string, account_name: string, location_name: string, title: string, address: ?string, maps_uri: ?string}
     */
    private function mapLocation(string $accountName, array $location): array
    {
        $shortName = (string) data_get($location, 'name');

        return [
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

    private function formatAddress(?array $storefrontAddress): ?string
    {
        if (! $storefrontAddress) {
            return null;
        }

        $parts = array_filter([
            implode(' ', (array) data_get($storefrontAddress, 'addressLines', [])),
            data_get($storefrontAddress, 'locality'),
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $query
     * @param  callable(array<string, mixed>): list<T>  $map
     * @param  array<string, mixed>  $context
     * @return list<T>
     */
    private function pages(string $token, string $url, array $query, string $message, callable $map, array $context = []): array
    {
        $items = [];
        $pageToken = null;

        do {
            $data = $this->request($token, 'get', $url, [...$query, 'pageToken' => $pageToken], $message, $context);
            array_push($items, ...$map($data));
            $pageToken = data_get($data, 'nextPageToken');
        } while (filled($pageToken));

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function request(
        string $token,
        string $method,
        string $url,
        array $data,
        string $message,
        array $context = [],
        string $level = 'error',
    ): array {
        $pending = $this->socialHttp()->withToken($token);
        $response = $method === 'post'
            ? $pending->post($url, $data)
            : $pending->get($url, array_filter($data));

        if ($response->failed()) {
            Log::log($level, $message, [
                ...$context,
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->throwFrom($response);
        }

        return $response->json() ?? [];
    }

    private function throwFrom(Response $response): never
    {
        throw GoogleBusinessPublishException::fromApiResponse($response);
    }

    private function url(string $key): string
    {
        return (string) config("trypost.platforms.google_business.{$key}");
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
