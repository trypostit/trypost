<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Services\Social\ContentSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Fails the content field once per platform and limit whose hard
 * `maxContentLength()` is exceeded by the submitted text. Pre-resolve the social
 * accounts (or bare platforms) the post is bound to and pass them in; an account
 * is measured against its own limit (an X account with long posts), a platform
 * against the platform's. Keeps the rule decoupled from the FormRequest payload.
 *
 * Each platform is measured against its own sanitized content, matching what the
 * publisher will actually send: the editor stores HTML, and per-platform rules
 * (X link defusing, Telegram entity escaping) change the length again. Measuring
 * the raw draft would block saving a post that publishes fine, and vice versa.
 * A captionless content type (a Story) sends no text, so it is not measured.
 */
class ContentFitsPlatformLimits implements ValidationRule
{
    /**
     * @param  Collection<int|string, Platform|SocialAccount>  $targets
     * @param  array<int|string, array<string, mixed>|null>  $meta  Per-target meta, keyed like $targets
     * @param  array<int|string, string|null>  $contentTypes  Per-target content type value, keyed like $targets
     */
    public function __construct(private Collection $targets, private array $meta = [], private array $contentTypes = []) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $content = (string) $value;
        $reported = [];

        foreach ($this->targets as $targetKey => $target) {
            $platform = $target instanceof SocialAccount ? $target->platform : $target;

            if (! $platform instanceof Platform || ContentType::tryFrom((string) data_get($this->contentTypes, $targetKey))?->isCaptionless()) {
                continue;
            }

            $display = app(ContentSanitizer::class)->displayText($content, $platform);

            if (! isset($reported["{$platform->value}:hashtags"]) && $platform->hashtagOverflow($display) > 0) {
                $reported["{$platform->value}:hashtags"] = true;
                $fail(trans('posts.form.hashtags_exceed_platform', [
                    'platform' => $platform->label(),
                    'limit' => $platform->maxHashtags(),
                ]));
            }

            $measure = $target instanceof SocialAccount ? $target : $platform;
            $limit = $measure->maxContentLength();
            $reserved = $platform->reservedLength(data_get($this->meta, $targetKey));
            $key = "{$platform->value}:{$limit}:{$reserved}";

            if (isset($reported[$key])) {
                continue;
            }

            $over = $measure->contentOverflow($display, $reserved);

            if ($over === 0) {
                continue;
            }

            $reported[$key] = true;
            $fail(trans('posts.form.content_exceeds_platform', [
                'platform' => $platform->label(),
                'limit' => $limit,
                'over' => $over,
            ]));
        }
    }
}
