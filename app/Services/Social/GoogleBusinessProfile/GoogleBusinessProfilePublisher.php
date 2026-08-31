<?php

declare(strict_types=1);

namespace App\Services\Social\GoogleBusinessProfile;

use App\Enums\PostPlatform\ContentType;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\GoogleBusinessProfilePublishException;
use App\Models\PostPlatform;
use App\Services\Social\ConnectionVerifier;
use Carbon\CarbonImmutable;

class GoogleBusinessProfilePublisher
{
    public function __construct(private readonly GoogleBusinessProfileApi $api) {}

    /** @return array<string, mixed> */
    public function publish(PostPlatform $postPlatform): array
    {
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

        $result = $this->api->createLocalPost($location, $this->payload($postPlatform));

        return [
            'id' => data_get($result, 'name'),
            'url' => data_get($result, 'searchUrl'),
            'provider_state' => data_get($result, 'state', 'PROCESSING'),
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
