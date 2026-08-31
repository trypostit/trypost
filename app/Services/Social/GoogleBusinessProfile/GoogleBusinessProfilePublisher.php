<?php

declare(strict_types=1);

namespace App\Services\Social\GoogleBusinessProfile;

use App\DataTransferObjects\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\GoogleBusinessProfilePublishException;
use App\Models\PostPlatform;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\ConnectionVerifier;
use App\Support\Social\GoogleBusinessProfileMediaDerivativeCleaner;
use App\Support\Social\PublishCheckpoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GoogleBusinessProfilePublisher
{
    public function __construct(
        private readonly GoogleBusinessProfileApi $api,
        private readonly MediaOptimizer $mediaOptimizer,
        private readonly GoogleBusinessProfileMediaDerivativeCleaner $derivativeCleaner,
    ) {}

    /** @return array<string, mixed> */
    public function publish(PostPlatform $postPlatform): array
    {
        if (! $postPlatform->content_type->isAuthorable()) {
            throw new GoogleBusinessProfilePublishException(
                userMessage: 'Google Business Profile alerts are no longer available for new posts.',
                category: ErrorCategory::ContentPolicy,
            );
        }

        $location = $postPlatform->googleBusinessProfileLocation;

        if (! $location || ! $location->is_selected || $location->social_account_id !== $postPlatform->social_account_id) {
            throw new GoogleBusinessProfilePublishException(
                userMessage: 'Choose a valid Google Business Profile location before publishing.',
                category: ErrorCategory::Permission,
            );
        }

        $account = $postPlatform->socialAccount;
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        [$payload, $derivativePath] = $this->preparePayload($postPlatform);

        try {
            $result = $this->api->createLocalPost($location, $payload);
        } catch (PlatformUnavailableException $e) {
            if ($derivativePath !== null) {
                $e->context[PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH] = $derivativePath;
            }

            throw $e;
        } catch (Throwable $e) {
            $this->derivativeCleaner->cleanupPath($derivativePath, $postPlatform->id);

            throw $e;
        }

        $platformPostId = data_get($result, 'name');
        if (! is_string($platformPostId) || $platformPostId === '') {
            $this->derivativeCleaner->cleanupPath($derivativePath, $postPlatform->id);

            throw new GoogleBusinessProfilePublishException(
                userMessage: 'Google Business Profile did not confirm the created post.',
                category: ErrorCategory::ServerError,
            );
        }

        $platformUrl = data_get($result, 'searchUrl');
        $state = (string) data_get($result, 'state', 'PROCESSING');
        $context = array_filter([
            'provider_state' => $state,
            PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH => $derivativePath,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if (in_array($state, ['LIVE', 'RECURRING'], true)) {
            $postPlatform->markAsPublished($platformPostId, $platformUrl);
            $this->derivativeCleaner->cleanupPath($derivativePath, $postPlatform->id);
        } elseif ($state === 'REJECTED') {
            $postPlatform->markAsRejected('Google rejected this post during review.', ['provider_state' => $state]);
            $this->derivativeCleaner->cleanupPath($derivativePath, $postPlatform->id);
        } else {
            $postPlatform->markAsSubmitted(
                $platformPostId,
                $platformUrl,
                $state === 'PROCESSING' ? Status::PendingReview : Status::Submitted,
                $context,
            );
        }

        return [
            'id' => $platformPostId,
            'url' => $platformUrl,
            'provider_state' => $state,
            'derivative_path' => $derivativePath,
        ];
    }

    /** @return array<string, mixed> */
    public function payload(PostPlatform $postPlatform): array
    {
        $meta = $postPlatform->meta ?? [];
        $payload = array_filter([
            'languageCode' => data_get($meta, 'language_code'),
            'summary' => $postPlatform->post->content,
            'topicType' => $this->topicType($postPlatform->content_type),
            'callToAction' => $this->callToAction($meta),
            'event' => $this->event($postPlatform->content_type, $meta),
            'offer' => $postPlatform->content_type === ContentType::GoogleBusinessProfileOffer ? array_filter([
                'couponCode' => data_get($meta, 'offer_coupon_code'),
                'redeemOnlineUrl' => data_get($meta, 'offer_redeem_url'),
                'termsConditions' => data_get($meta, 'offer_terms'),
            ], fn (mixed $value): bool => filled($value)) : null,
            'alertType' => $postPlatform->content_type === ContentType::GoogleBusinessProfileAlert
                ? data_get($meta, 'alert_type')
                : null,
        ], fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        if ($media = $postPlatform->post->mediaItems->first()) {
            $payload['media'] = [['sourceUrl' => $media->url]];
        }

        return $payload;
    }

    /** @return array{0: array<string, mixed>, 1: string|null} */
    private function preparePayload(PostPlatform $postPlatform): array
    {
        $payload = $this->payload($postPlatform);

        $derivativePath = null;
        $media = $postPlatform->post->mediaItems->first();
        if ($media?->isImage()) {
            [$sourceUrl, $derivativePath] = $this->resolveImageDerivative($media, $postPlatform);
            data_set($payload, 'media.0.sourceUrl', $sourceUrl);
        }

        return [$payload, $derivativePath];
    }

    /** @return array{0: string, 1: string} */
    private function resolveImageDerivative(MediaItem $media, PostPlatform $postPlatform): array
    {
        $existingPath = PublishCheckpoint::googleBusinessProfileDerivativePath($postPlatform->error_context);
        if ($this->derivativeCleaner->isManagedDerivativePath($existingPath)) {
            try {
                if (Storage::exists($existingPath)) {
                    return [$this->absoluteStorageUrl($existingPath), $existingPath];
                }
            } catch (Throwable) {
                throw new PlatformUnavailableException('Media storage temporarily failed while reusing the Google Business Profile image derivative.');
            }
        }

        $input = tempnam(sys_get_temp_dir(), 'gbp_input_');
        if ($input === false) {
            throw new GoogleBusinessProfilePublishException(
                userMessage: 'Failed to prepare image for Google Business Profile.',
                category: ErrorCategory::ServerError,
            );
        }

        $optimized = null;
        $derivativePath = null;

        try {
            try {
                $sourceExists = $media->path !== '' && Storage::exists($media->path);
            } catch (Throwable) {
                throw new PlatformUnavailableException('Media storage temporarily failed while preparing the Google Business Profile image.');
            }

            if (! $sourceExists) {
                throw new GoogleBusinessProfilePublishException(
                    userMessage: 'The image for this Google Business Profile post is no longer available.',
                    category: ErrorCategory::ServerError,
                );
            }

            $maxSourceBytes = (int) config('trypost.media.max_size_mb.image', 10) * 1024 * 1024;
            try {
                $sourceSize = Storage::size($media->path);
                $source = Storage::get($media->path);
            } catch (Throwable) {
                throw new PlatformUnavailableException('Media storage temporarily failed while preparing the Google Business Profile image.');
            }

            if ($sourceSize <= 0 || $sourceSize > $maxSourceBytes) {
                throw new GoogleBusinessProfilePublishException(
                    userMessage: 'The image is too large for Google Business Profile.',
                    category: ErrorCategory::MediaFormat,
                );
            }

            if (strlen($source) !== $sourceSize || file_put_contents($input, $source) === false) {
                throw new PlatformUnavailableException('Media storage temporarily failed while preparing the Google Business Profile image.');
            }

            $optimized = $this->mediaOptimizer->optimizeImage($input, Platform::GoogleBusinessProfile);
            if (mime_content_type($optimized) !== 'image/jpeg') {
                throw new GoogleBusinessProfilePublishException(
                    userMessage: 'Failed to prepare image for Google Business Profile.',
                    category: ErrorCategory::MediaFormat,
                );
            }

            $derivativePath = GoogleBusinessProfileMediaDerivativeCleaner::DIRECTORY.'/'.Str::uuid()->toString().'.jpg';
            try {
                $stored = Storage::put($derivativePath, file_get_contents($optimized));
            } catch (Throwable) {
                throw new PlatformUnavailableException('Media storage temporarily failed while preparing the Google Business Profile image.');
            }

            if (! $stored) {
                throw new PlatformUnavailableException('Media storage temporarily failed while preparing the Google Business Profile image.');
            }

            return [$this->absoluteStorageUrl($derivativePath), $derivativePath];
        } catch (GoogleBusinessProfilePublishException|PlatformUnavailableException $e) {
            $this->derivativeCleaner->cleanupPath($derivativePath, $postPlatform->id);

            throw $e;
        } catch (Throwable $e) {
            $this->derivativeCleaner->cleanupPath($derivativePath, $postPlatform->id);

            throw new GoogleBusinessProfilePublishException(
                userMessage: 'Failed to prepare image for Google Business Profile.',
                category: ErrorCategory::ServerError,
            );
        } finally {
            @unlink($input);
            if ($optimized !== null) {
                @unlink($optimized);
            }
        }
    }

    private function absoluteStorageUrl(string $path): string
    {
        $storageUrl = Storage::url($path);

        return Str::startsWith($storageUrl, ['http://', 'https://']) ? $storageUrl : url($storageUrl);
    }

    /** @param array<string, mixed> $meta
     * @return array<string, string>|null
     */
    private function callToAction(array $meta): ?array
    {
        $type = data_get($meta, 'cta_action_type');
        if (blank($type)) {
            return null;
        }

        return array_filter([
            'actionType' => $type,
            'url' => $type === 'CALL' ? null : data_get($meta, 'cta_url'),
        ], fn (mixed $value): bool => filled($value));
    }

    /** @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    private function event(ContentType $contentType, array $meta): ?array
    {
        if (! in_array($contentType, [ContentType::GoogleBusinessProfileEvent, ContentType::GoogleBusinessProfileOffer], true)) {
            return null;
        }

        $start = CarbonImmutable::parse((string) data_get($meta, 'event_start_at'));
        $end = CarbonImmutable::parse((string) data_get($meta, 'event_end_at'));

        return array_filter([
            'title' => data_get($meta, 'event_title'),
            'schedule' => [
                'startDate' => $this->date($start),
                'startTime' => $this->time($start),
                'endDate' => $this->date($end),
                'endTime' => $this->time($end),
            ],
            'recurrenceInfo' => $this->recurrence($meta),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    private function recurrence(array $meta): ?array
    {
        $pattern = data_get($meta, 'recurrence_pattern');
        if (blank($pattern)) {
            return null;
        }

        $key = match ($pattern) {
            'daily' => 'dailyPattern',
            'weekly' => 'weeklyPattern',
            'monthly' => 'monthlyPattern',
            default => null,
        };
        if ($key === null) {
            return null;
        }

        $patternPayload = match ($pattern) {
            'daily' => [],
            'weekly' => ['daysOfWeek' => data_get($meta, 'recurrence_days_of_week', [])],
            'monthly' => array_filter([
                'dayOfMonth' => data_get($meta, 'recurrence_day_of_month'),
                'dayOfWeekOccurrence' => data_get($meta, 'recurrence_day_of_week_occurrence'),
            ], fn (mixed $value): bool => filled($value)),
        };

        return array_filter([
            'seriesEndTime' => filled(data_get($meta, 'recurrence_series_end_at'))
                ? CarbonImmutable::parse((string) data_get($meta, 'recurrence_series_end_at'))->toRfc3339String()
                : null,
            $key => (object) $patternPayload,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array{year: int, month: int, day: int} */
    private function date(CarbonImmutable $date): array
    {
        return ['year' => $date->year, 'month' => $date->month, 'day' => $date->day];
    }

    /** @return array{hours: int, minutes: int, seconds: int, nanos: int} */
    private function time(CarbonImmutable $date): array
    {
        return ['hours' => $date->hour, 'minutes' => $date->minute, 'seconds' => $date->second, 'nanos' => 0];
    }

    private function topicType(ContentType $contentType): string
    {
        return match ($contentType) {
            ContentType::GoogleBusinessProfileStandard => 'STANDARD',
            ContentType::GoogleBusinessProfileEvent => 'EVENT',
            ContentType::GoogleBusinessProfileOffer => 'OFFER',
            ContentType::GoogleBusinessProfileAlert => 'ALERT',
            default => throw new \LogicException('Unsupported Google Business Profile content type.'),
        };
    }
}
