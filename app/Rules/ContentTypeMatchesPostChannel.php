<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ContentTypeMatchesPostChannel implements ValidationRule
{
    public function __construct(private Post $post) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $contentType = ContentType::tryFrom((string) $value);
        $account = $this->post->socialAccount;

        if (! $contentType || ! $account) {
            return;
        }

        if (! in_array($account->platform, $contentType->compatiblePlatforms(), true)) {
            $fail(sprintf(
                'content_type "%s" is not compatible with the %s account.',
                $contentType->value,
                $account->platform->label(),
            ));
        }
    }
}
