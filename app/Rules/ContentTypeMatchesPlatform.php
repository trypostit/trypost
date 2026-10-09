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
 * sent next to it: the top-level `social_account_id`, or the sibling of each
 * `destinations.*.content_type`.
 */
class ContentTypeMatchesPlatform implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * An account outside `$workspaceId` is left to the `exists` rule, so its
     * network is never revealed.
     */
    public function __construct(private ?string $workspaceId) {}

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
        $accountId = data_get($this->data, self::siblingAccountPath($attribute));

        if (! $accountId || ! Str::isUuid((string) $accountId)) {
            return;
        }

        $contentType = ContentType::tryFrom((string) $value);
        $account = SocialAccount::query()->where('workspace_id', $this->workspaceId)->find($accountId);

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

    /**
     * The account sent next to the content type: `social_account_id` at the top,
     * `destinations.{i}.social_account_id` inside a destination.
     */
    private static function siblingAccountPath(string $attribute): string
    {
        $parent = Str::beforeLast($attribute, '.');

        return $parent === $attribute ? 'social_account_id' : "{$parent}.social_account_id";
    }
}
