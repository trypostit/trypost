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
 * Ensures the chosen content_type is supported by the platform of the account
 * it is sent with: a top-level `social_account_id` (pass its attribute name)
 * or the sibling of each `destinations.*.content_type`.
 */
class ContentTypeMatchesPlatform implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * `$accountAttribute` names the field holding the account id; without it
     * the rule reads the sibling `social_account_id` of the content type.
     */
    public function __construct(private ?string $accountAttribute = null) {}

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
        $accountId = data_get($this->data, $this->accountAttribute ?? Str::beforeLast($attribute, '.').'.social_account_id');

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
