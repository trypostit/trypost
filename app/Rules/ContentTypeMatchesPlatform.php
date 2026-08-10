<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Cross-validates platforms[].content_type against platforms[].social_account_id
 * — ensures the chosen content_type is supported by the account's platform.
 *
 * Use on `platforms.*.content_type` rules in form requests / tools that accept
 * a `platforms[]` array with `social_account_id` siblings.
 */
class ContentTypeMatchesPlatform implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // attribute is e.g. "platforms.0.content_type" — peel off the leaf
        // and look up the sibling social_account_id under the same parent.
        $parentKey = Str::beforeLast($attribute, '.');
        $accountId = data_get($this->data, $parentKey.'.social_account_id');

        if (! $accountId || ! Str::isUuid((string) $accountId)) {
            return;
        }

        $contentType = ContentType::tryFrom((string) $value);
        $account = SocialAccount::find($accountId);

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
