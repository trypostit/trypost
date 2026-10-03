<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Actions\Media\SyncOwnedMedia;
use App\Dto\MediaItem;
use App\Enums\Post\QueuePosition;
use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Rules\PostContentFitsMaxLength;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

class PostCompositionValidator
{
    /**
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    public static function validate(Workspace $workspace, array $composition, array $existingMedia = []): array
    {
        Validator::make($composition, [
            'status' => ['required', Rule::in(['draft', 'scheduled', 'publishing'])],
            'content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            'media' => ['sometimes', 'array'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => ['required', 'uuid'],
            'destinations.*.content_type' => ['required', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'destinations.*.meta' => ['sometimes', 'array'],
            'destinations.*.content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            'destinations.*.media' => ['sometimes', 'array'],
            'scheduled_at' => [
                Rule::requiredIf(fn (): bool => data_get($composition, 'status') === 'scheduled' && blank(data_get($composition, 'queue'))),
                'nullable',
                'date',
                'after:now',
                'before:2038-01-19',
            ],
            'queue' => PostStatusRules::queueRules(),
            'queue_slot' => ['nullable', 'date', 'prohibited_unless:status,scheduled', 'prohibits:queue'],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)],
        ], PostStatusRules::queueMessages())->validate();

        $composition['queue'] = filled(data_get($composition, 'queue'))
            ? QueuePosition::from(data_get($composition, 'queue'))
            : null;

        $composition['destinations'] = collect($composition['destinations'] ?? [])
            ->map(function (array $destination, int $index) use ($composition): array {
                $destination['media_error_key'] = array_key_exists('media', $destination)
                    ? "destinations.{$index}.media"
                    : 'media';
                $destination['content'] = array_key_exists('content', $destination)
                    ? $destination['content']
                    : ($composition['content'] ?? '');
                $destination['media'] = array_key_exists('media', $destination)
                    ? self::inheritSharedAltText($destination['media'], $composition['media'] ?? [])
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
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->unique();
        $assets = ResolveWorkspaceMedia::execute($workspace, $mediaIds->values()->all());

        foreach ($composition['destinations'] as &$destination) {
            foreach ($destination['media'] as &$media) {
                $asset = $assets->get(data_get($media, 'id'));
                if (! $asset || $asset->path !== data_get($media, 'path') || $asset->url !== data_get($media, 'url')) {
                    continue;
                }

                $canonical = MediaItem::fromMedia($asset)->toArray();
                $edits = array_intersect_key((array) data_get($media, 'meta', []), array_flip(SyncOwnedMedia::EDITABLE_META));
                if ($edits !== []) {
                    $canonical['meta'] = [...($asset->meta ?? []), ...$edits];
                }
                foreach (['source', 'source_meta'] as $field) {
                    if (array_key_exists($field, $media)) {
                        $canonical[$field] = $media[$field];
                    }
                }
                if (filled(data_get($media, 'upload_token'))) {
                    $canonical['upload_token'] = $media['upload_token'];
                }
                $media = $canonical;
            }
            unset($media);
        }
        unset($destination);

        $mediaRules = [];
        foreach (PostMediaRules::hostedRules() as $key => $rules) {
            $mediaRules[str_replace('media', 'destinations.*.media', $key)] = $rules;
        }
        $metaRules = [];
        foreach (PostPlatformMetaRules::rules() as $key => $rules) {
            $metaRules[str_replace('platforms.', 'destinations.', $key)] = array_map(
                fn (mixed $rule): mixed => is_string($rule)
                    ? str_replace('platforms.', 'destinations.', $rule)
                    : $rule,
                $rules,
            );
        }
        $metaMessages = [];
        foreach (PostPlatformMetaRules::messages() as $key => $message) {
            $metaMessages[str_replace('platforms.', 'destinations.', $key)] = $message;
        }
        $metaAttributes = [];
        foreach (PostPlatformMetaRules::attributes() as $key => $attribute) {
            $metaAttributes[str_replace('platforms.', 'destinations.', $key)] = $attribute;
        }

        $validator = Validator::make($composition, [...$mediaRules, ...$metaRules], $metaMessages, $metaAttributes);
        $validator->after(function (LaravelValidator $validator) use ($composition, $accounts, $assets, $existingMedia, $workspace): void {
            $seen = [];

            foreach ($composition['destinations'] as $index => $destination) {
                $accountId = $destination['social_account_id'];
                $account = $accounts->get($accountId);
                $key = "destinations.{$index}";

                if (isset($seen[$accountId])) {
                    $validator->errors()->add("{$key}.social_account_id", trans('validation.distinct', ['attribute' => 'social account']));
                }
                $seen[$accountId] = true;

                if ($account === null) {
                    $validator->errors()->add("{$key}.social_account_id", trans('validation.exists', ['attribute' => 'social account']));

                    continue;
                }

                if ($composition['queue'] !== null && ! $account->hasPostingSchedule()) {
                    $validator->errors()->add("{$key}.social_account_id", __('posts.errors.queue_requires_schedule'));
                }

                $contentType = ContentType::tryFrom($destination['content_type']);
                if ($contentType && ! in_array($account->platform, $contentType->compatiblePlatforms(), true)) {
                    $validator->errors()->add("{$key}.content_type", trans('validation.in', ['attribute' => 'content type']));
                }

                foreach ($destination['media'] as $mediaIndex => $media) {
                    $asset = $assets->get(data_get($media, 'id'));
                    if (in_array(self::comparableMedia($media), array_map(self::comparableMedia(...), $existingMedia), true)) {
                        continue;
                    }

                    if (! $asset || $asset->path !== data_get($media, 'path')) {
                        $validator->errors()->add("{$key}.media.{$mediaIndex}.id", ! $asset && filled(data_get($media, 'upload_token'))
                            ? __('posts.errors.media_expired')
                            : trans('validation.exists', ['attribute' => 'media']));

                        continue;
                    }

                    if ($asset->url !== data_get($media, 'url')) {
                        $validator->errors()->add("{$key}.media.{$mediaIndex}.url", trans('validation.in', ['attribute' => 'media URL']));
                    }
                }

                $formatViolation = PostPlatformMetaRules::formatViolation($account->platform, $destination['meta'] ?? []);
                if ($formatViolation !== null) {
                    [$field, $message] = $formatViolation;
                    $validator->errors()->add("{$key}.meta.{$field}", $message);
                }

                if ($composition['status'] === 'draft') {
                    continue;
                }

                $contentValidator = Validator::make(
                    ['content' => $destination['content']],
                    ['content' => [new ContentFitsPlatformLimits(collect([$account]), [$destination['meta'] ?? []], [$destination['content_type']])]],
                );
                if ($contentValidator->fails()) {
                    $validator->errors()->add("{$key}.content", $contentValidator->errors()->first('content'));
                }

                if (blank($destination['content']) && $destination['media'] === []) {
                    $validator->errors()->add("{$key}.content", trans('posts.edit.compliance.requires_content_or_media'));
                }

                $metaViolation = PostPlatformMetaRules::requiredMetaViolation($account->platform, $destination['meta'] ?? []);
                if ($metaViolation !== null && $metaViolation !== $formatViolation) {
                    [$field, $message] = $metaViolation;
                    $validator->errors()->add("{$key}.meta.{$field}", $message);
                }

                foreach (ContentTypeCompatibleWithMedia::errorsFor([[
                    'key' => "{$key}.content_type",
                    'content_type' => $destination['content_type'],
                    'aspect_ratio' => data_get($destination, 'meta.aspect_ratio'),
                ]], $destination['media'], $workspace, $destination['content']) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $composition;
    }

    /**
     * A destination's own media keeps its alt text; an item without one takes
     * the alt text of the same shared item, so the shared alt reaches every channel.
     *
     * @param  array<int, mixed>  $media
     * @param  array<int, mixed>  $shared
     * @return array<int, mixed>
     */
    private static function inheritSharedAltText(array $media, array $shared): array
    {
        $sharedAltText = collect($shared)
            ->filter(fn (mixed $item): bool => is_array($item) && is_string(data_get($item, 'id')) && filled(data_get($item, 'meta.alt_text')))
            ->mapWithKeys(fn (array $item): array => [data_get($item, 'id') => data_get($item, 'meta.alt_text')]);

        return array_map(function (mixed $item) use ($sharedAltText): mixed {
            if (! is_array($item) || ! is_string($id = data_get($item, 'id')) || filled(data_get($item, 'meta.alt_text'))) {
                return $item;
            }

            $altText = $sharedAltText->get($id);

            if ($altText !== null) {
                $item['meta'] = [...(array) data_get($item, 'meta', []), 'alt_text' => $altText];
            }

            return $item;
        }, $media);
    }

    /**
     * Alt text, people tags and the video cover offset (`SyncOwnedMedia::EDITABLE_META`)
     * are the per-post edits the composer may make to a media item it did not
     * upload, so they are ignored when matching it to the post's stored media.
     * Keys are sorted because neither the browser nor MySQL's JSON column keeps
     * the stored key order.
     *
     * @param  array<string, mixed>  $media
     * @return array<string, mixed>
     */
    private static function comparableMedia(array $media): array
    {
        if (is_array(data_get($media, 'meta'))) {
            $media['meta'] = array_diff_key($media['meta'], array_flip(SyncOwnedMedia::EDITABLE_META));
        }

        $media = array_filter($media, fn (mixed $value): bool => $value !== null && $value !== []);

        return self::sortKeys($media);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => is_array($item) ? self::sortKeys($item) : $item, $value);
    }
}
