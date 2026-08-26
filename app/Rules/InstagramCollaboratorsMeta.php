<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\SocialAccount\Platform;
use App\Support\PostPlatformMetaRules;
use App\Support\Social\InstagramCollaborators;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * Instagram-only collaborator shape (max 3, username, not self). Other networks
 * may reuse `meta.collaborators` without inheriting these constraints.
 */
class InstagramCollaboratorsMeta implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    private ?Validator $validator = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $platform = PostPlatformMetaRules::platformForAttribute($this->data, $attribute);

        if (
            ! is_array($value)
            || ! in_array($platform, [Platform::Instagram, Platform::InstagramFacebook], true)
            || PostPlatformMetaRules::dropsCollaborators(PostPlatformMetaRules::contentTypeForAttribute($this->data, $attribute))
        ) {
            return;
        }

        $ownUsername = PostPlatformMetaRules::accountForAttribute($this->data, $attribute)?->username;

        ['items' => $items, 'exceedsMax' => $exceedsMax] = InstagramCollaborators::failures($value, $ownUsername);

        foreach ($items as $index => $reason) {
            $this->validator?->errors()->add("{$attribute}.{$index}", __("posts.form.instagram.collaborators_{$reason}"));
        }

        if ($exceedsMax) {
            $fail(__('posts.form.instagram.collaborators_max'));
        }
    }
}
