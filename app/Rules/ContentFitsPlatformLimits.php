<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\SocialAccount\Platform;
use App\Services\Social\ContentSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Fails the content field once per platform whose hard `maxContentLength()` is
 * exceeded by the submitted text. Pre-resolve the platforms the post is bound
 * to (App: from `post_platforms.id`; API store: from `social_accounts.id`) and
 * pass them in — keeps the rule decoupled from the FormRequest payload shape.
 *
 * Each platform is measured against its own sanitized content, matching what the
 * publisher will actually send: the editor stores HTML, and per-platform rules
 * (X link defusing, Telegram entity escaping) change the length again. Measuring
 * the raw draft would block saving a post that publishes fine, and vice versa.
 */
class ContentFitsPlatformLimits implements ValidationRule
{
    /**
     * @param  Collection<int|string, Platform>  $platforms
     */
    public function __construct(private Collection $platforms) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $content = (string) $value;
        $reported = [];

        foreach ($this->platforms as $platform) {
            if (! $platform instanceof Platform) {
                continue;
            }

            if (isset($reported[$platform->value])) {
                continue;
            }

            $over = $platform->contentOverflow(app(ContentSanitizer::class)->displayText($content, $platform));
            if ($over === 0) {
                continue;
            }

            $reported[$platform->value] = true;
            $fail(trans('posts.form.content_exceeds_platform', [
                'platform' => $platform->label(),
                'limit' => $platform->maxContentLength(),
                'over' => $over,
            ]));
        }
    }
}
