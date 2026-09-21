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

    public function publish(PostPlatform $postPlatform): array
    {
        $state = null;

        try {
            $this->validateContentLength($postPlatform);

            $account = $postPlatform->socialAccount;

            if ($account->needsProactiveTokenRefresh()) {
                app(ConnectionVerifier::class)->refreshToken($account);
            }

            $locationId = $this->required(
                data_get($account->meta, 'location_id'),
                __('posts.errors.google_business.no_location'),
                ErrorCategory::Permission,
            );
            $created = $this->post(
                $account->access_token,
                $this->url('local_posts_api', "/{$locationId}/localPosts"),
                $this->payload($postPlatform),
                'Google Business Profile post creation failed',
            );
            $state = LocalPostState::fromApi(data_get($created, 'state'));

            return [
                'id' => (string) data_get($created, 'name'),
                'url' => (string) (data_get($created, 'searchUrl') ?: GoogleBusinessResourceName::dashboardUrl($locationId)),
                'state' => $state->value,
            ];
        } finally {
            if (! $state?->isPendingReview()) {
                app(GoogleBusinessDerivativeCleaner::class)->cleanup($postPlatform->id);
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
        return collect($this->accountNames($accessToken))
            ->flatMap(fn (string $accountName) => $this->locationsFor($accessToken, $accountName))
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
        $payload = $this->getOrNull(
            $accessToken,
            $this->url('local_posts_api', "/{$fullLocationName}/media/profile"),
            'Google Business Profile location profile photo fetch failed',
            ['location' => $fullLocationName],
        );
        $url = data_get($payload, 'thumbnailUrl') ?: data_get($payload, 'googleUrl');

        return filled($url) ? (string) $url : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchLocalPost(SocialAccount $account, string $localPostName): array
    {
        return $this->get(
            $account->access_token,
            $this->url('local_posts_api', "/{$localPostName}"),
            [],
            'Google Business Profile post lookup failed',
            level: 'warning',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PostPlatform $postPlatform): array
    {
        $topicType = TopicType::fromMeta(data_get($postPlatform->meta, 'topic_type'));
        $language = ContentLanguage::tryFrom((string) $postPlatform->post->workspace->content_language)
            ?? ContentLanguage::DEFAULT;

        return [
            'languageCode' => $language->bcp47(),
            'summary' => $postPlatform->post->content
                ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, Platform::GoogleBusiness)
                : '',
            'topicType' => $topicType->value,
            ...array_filter([
                'callToAction' => $this->callToAction($postPlatform, $topicType),
                'media' => $this->photo($postPlatform),
                'event' => $topicType->requiresEvent() ? $this->event($postPlatform, $topicType) : null,
                'offer' => $topicType === TopicType::Offer ? $this->offer($postPlatform) : null,
            ]),
        ];
    }

    /**
     * @return array{actionType: string, url?: mixed}|null
     */
    private function callToAction(PostPlatform $postPlatform, TopicType $topicType): ?array
    {
        $action = CtaAction::fromMeta(data_get($postPlatform->meta, 'call_to_action.action_type'));

        if (! $topicType->allowsCallToAction() || $action === CtaAction::None) {
            return null;
        }

        return [
            'actionType' => $action->value,
            ...($action->requiresUrl() ? [
                'url' => data_get($postPlatform->meta, 'call_to_action.url'),
            ] : []),
        ];
    }

    /**
     * @return list<array{mediaFormat: string, sourceUrl: string}>|null
     */
    private function photo(PostPlatform $postPlatform): ?array
    {
        $media = $postPlatform->post->mediaItems->first(
            fn (MediaItem $item): bool => $item->isImage() && ! MediaType::isGif($item->mime_type),
        );

        return $media ? [[
            'mediaFormat' => 'PHOTO',
            'sourceUrl' => $this->imageSourceUrl($media, $postPlatform->id),
        ]] : null;
    }

    private function imageSourceUrl(MediaItem $media, string $postPlatformId): string
    {
        if (blank($media->path) || ! Storage::exists($media->path)) {
            return $media->url;
        }

        $input = tempnam(sys_get_temp_dir(), 'gbp_');
        $optimized = null;

        if ($input === false) {
            return $media->url;
        }

        try {
            file_put_contents($input, Storage::get($media->path));
            $optimized = app(MediaOptimizer::class)->optimizeImage($input, Platform::GoogleBusiness);
            $path = GoogleBusinessDerivativeCleaner::pathFor($postPlatformId);
            Storage::put($path, file_get_contents($optimized));

            return Storage::url($path);
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
     * @return array{title: string, schedule: array<string, mixed>}
     */
    private function event(PostPlatform $postPlatform, TopicType $topicType): array
    {
        $datesRequired = __('posts.errors.google_business.event_dates_required');

        return [
            'title' => $this->required(
                data_get($postPlatform->meta, 'event.title'),
                $topicType === TopicType::Offer
                    ? __('posts.form.google_business.offer_title_required')
                    : __('posts.form.google_business.event_title_required'),
            ),
            'schedule' => array_filter([
                'startDate' => $this->dateParts($this->required(data_get($postPlatform->meta, 'event.start_date'), $datesRequired)),
                'endDate' => $this->dateParts($this->required(data_get($postPlatform->meta, 'event.end_date'), $datesRequired)),
                'startTime' => $this->timeParts(data_get($postPlatform->meta, 'event.start_time')),
                'endTime' => $this->timeParts(data_get($postPlatform->meta, 'event.end_time')),
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function offer(PostPlatform $postPlatform): array
    {
        return array_filter([
            'couponCode' => data_get($postPlatform->meta, 'offer.coupon_code'),
            'redeemOnlineUrl' => data_get($postPlatform->meta, 'offer.redeem_online_url'),
            'termsConditions' => data_get($postPlatform->meta, 'offer.terms_conditions'),
        ], filled(...));
    }

    private function required(mixed $value, string $message, ErrorCategory $category = ErrorCategory::ContentPolicy): string
    {
        $value = (string) $value;

        if (blank($value)) {
            throw new GoogleBusinessPublishException(userMessage: $message, category: $category);
        }

        return $value;
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
     * @return array{hours: int, minutes: int, seconds: int, nanos: int}|null
     */
    private function timeParts(mixed $time): ?array
    {
        if (blank($time)) {
            return null;
        }

        $carbon = CarbonImmutable::parse((string) $time);

        return ['hours' => $carbon->hour, 'minutes' => $carbon->minute, 'seconds' => 0, 'nanos' => 0];
    }

    /**
     * @return list<string>
     */
    private function accountNames(string $accessToken): array
    {
        return $this->pages(
            $accessToken,
            $this->url('account_management_api', '/accounts'),
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
    private function locationsFor(string $accessToken, string $accountName): array
    {
        return $this->pages(
            $accessToken,
            $this->url('business_information_api', "/{$accountName}/locations"),
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
        $mapsUri = data_get($location, 'metadata.mapsUri');

        return [
            'id' => GoogleBusinessResourceName::toFullLocationName($accountName, $shortName),
            'account_name' => $accountName,
            'location_name' => $shortName,
            'title' => (string) data_get($location, 'title'),
            'address' => $this->formatAddress(data_get($location, 'storefrontAddress')),
            'maps_uri' => filled($mapsUri) ? (string) $mapsUri : null,
        ];
    }

    private function formatAddress(mixed $storefrontAddress): ?string
    {
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
            $data = $this->get(
                $token,
                $url,
                [...$query, ...filled($pageToken) ? ['pageToken' => $pageToken] : []],
                $message,
                $context,
            );
            array_push($items, ...$map($data));
            $pageToken = data_get($data, 'nextPageToken');
        } while (filled($pageToken));

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(string $token, string $url, array $data, string $message): array
    {
        return $this->json($this->socialHttp()->withToken($token)->post($url, $data), $message);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function get(string $token, string $url, array $query, string $message, array $context = [], string $level = 'error'): array
    {
        return $this->json($this->socialHttp()->withToken($token)->get($url, $query), $message, $context, $level);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function getOrNull(string $token, string $url, string $message, array $context = []): ?array
    {
        $response = $this->socialHttp()->withToken($token)->get($url);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning($message, [
            ...$context,
            'status' => $response->status(),
            'body' => $this->redactResponseBody($response->body()),
        ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function json(Response $response, string $message, array $context = [], string $level = 'error'): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        Log::log($level, $message, [
            ...$context,
            'status' => $response->status(),
            'body' => $this->redactResponseBody($response->body()),
        ]);

        throw GoogleBusinessPublishException::fromApiResponse($response);
    }

    private function url(string $key, string $path = ''): string
    {
        return (string) config("trypost.platforms.google_business.{$key}").$path;
    }
}
