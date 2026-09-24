<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeCompatibleWithMedia;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

class PostCompositionValidator
{
    /**
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    public static function validate(Workspace $workspace, array $composition): array
    {
        Validator::make($composition, [
            'status' => ['required', Rule::in(['draft', 'scheduled', 'publishing'])],
            'content' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'media' => ['sometimes', 'array'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => ['required', 'uuid'],
            'destinations.*.content_type' => ['required', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'destinations.*.meta' => ['sometimes', 'array'],
            'destinations.*.content' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'destinations.*.media' => ['sometimes', 'array'],
            'scheduled_at' => ['required_if:status,scheduled', 'nullable', 'date', 'after:now', 'before:2038-01-19'],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)],
        ])->validate();

        $composition['destinations'] = collect($composition['destinations'] ?? [])
            ->map(function (array $destination) use ($composition): array {
                $destination['content'] = array_key_exists('content', $destination)
                    ? $destination['content']
                    : ($composition['content'] ?? '');
                $destination['media'] = array_key_exists('media', $destination)
                    ? $destination['media']
                    : ($composition['media'] ?? []);

                return $destination;
            })->all();

        $accountIds = collect($composition['destinations'])->pluck('social_account_id');
        $accounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');
        $mediaIds = collect($composition['destinations'])
            ->flatMap(fn (array $destination): array => array_column($destination['media'], 'id'))
            ->filter()
            ->unique();
        $assets = $workspace->media()->whereIn('id', $mediaIds)->get()->keyBy('id');

        $mediaRules = [];
        foreach (PostMediaRules::rules(hosted: true) as $key => $rules) {
            $mediaRules[str_replace('media', 'destinations.*.media', $key)] = $rules;
        }
        $metaRules = [];
        foreach (PostPlatformMetaRules::rules() as $key => $rules) {
            $metaRules[str_replace('platforms.', 'destinations.', $key)] = $rules;
        }

        $validator = Validator::make($composition, [...$mediaRules, ...$metaRules]);
        $validator->after(function (LaravelValidator $validator) use ($composition, $accounts, $assets): void {
            $seen = [];

            foreach ($composition['destinations'] as $index => $destination) {
                $accountId = $destination['social_account_id'];
                $account = $accounts->get($accountId);
                $key = "destinations.{$index}";

                if (isset($seen[$accountId])) {
                    $validator->errors()->add("{$key}.social_account_id", trans('validation.distinct', ['attribute' => 'social account']));
                }
                $seen[$accountId] = true;

                if (! $account?->is_active) {
                    $validator->errors()->add("{$key}.social_account_id", trans('validation.exists', ['attribute' => 'social account']));

                    continue;
                }

                $contentType = ContentType::tryFrom($destination['content_type']);
                if ($contentType && ! in_array($account->platform, $contentType->compatiblePlatforms(), true)) {
                    $validator->errors()->add("{$key}.content_type", trans('validation.in', ['attribute' => 'content type']));
                }

                foreach ($destination['media'] as $mediaIndex => $media) {
                    $asset = $assets->get(data_get($media, 'id'));
                    if (! $asset || $asset->path !== data_get($media, 'path')) {
                        $validator->errors()->add("{$key}.media.{$mediaIndex}.id", trans('validation.exists', ['attribute' => 'media']));

                        continue;
                    }

                    if ($asset->url !== data_get($media, 'url')) {
                        $validator->errors()->add("{$key}.media.{$mediaIndex}.url", trans('validation.in', ['attribute' => 'media URL']));
                    }
                }

                if ($composition['status'] === 'draft') {
                    continue;
                }

                $contentValidator = Validator::make(
                    ['content' => $destination['content']],
                    ['content' => [new ContentFitsPlatformLimits(collect([$account->platform]))]],
                );
                if ($contentValidator->fails()) {
                    $validator->errors()->add("{$key}.content", $contentValidator->errors()->first('content'));
                }

                if (blank($destination['content']) && $destination['media'] === []) {
                    $validator->errors()->add("{$key}.content", trans('posts.edit.compliance.requires_content_or_media'));
                }

                $metaViolation = PostPlatformMetaRules::requiredMetaViolation($account->platform, $destination['meta'] ?? []);
                if ($metaViolation !== null) {
                    [$field, $message] = $metaViolation;
                    $validator->errors()->add("{$key}.meta.{$field}", $message);
                }

                foreach (ContentTypeCompatibleWithMedia::errorsFor([[
                    'key' => "{$key}.content_type",
                    'content_type' => $destination['content_type'],
                ]], $destination['media']) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $composition;
    }
}
