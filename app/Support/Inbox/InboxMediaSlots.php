<?php

declare(strict_types=1);

namespace App\Support\Inbox;

use App\Enums\Inbox\DirectMessageContent;
use App\Enums\Media\Type as MediaType;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The places an inbox row shows a file (an avatar, a media item's file or
 * thumbnail, a DM's shared content). A row stores only paths on our disk
 * there; a slot is a dotted path into the row's attributes
 * (`media.0.path`). A network link waits in `meta.pending_media` (slot with
 * dots written as colons -> link) until the file is copied, and
 * `meta.stored_media` maps each handled slot to the medias row that holds
 * its copy, or to `unstored` once the file could not be copied (the slot
 * then stays empty).
 */
final class InboxMediaSlots
{
    public const string MARKERS = 'stored_media';

    public const string PENDING = 'pending_media';

    public const string UNSTORED = 'unstored';

    public static function isNetworkHosted(mixed $url): bool
    {
        return is_string($url)
            && in_array(Str::lower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && filled(parse_url($url, PHP_URL_HOST))
            && self::ownPath($url) === null;
    }

    public static function url(mixed $path): ?string
    {
        return is_string($path) && filled($path) ? Storage::url($path) : null;
    }

    /**
     * Stored media items as the client reads them; an item whose file is not
     * on our disk is left out.
     *
     * @param  array<int, mixed>|null  $items
     * @return list<array{type: mixed, url: string, thumbnail_url: ?string}>
     */
    public static function itemUrls(?array $items): array
    {
        return collect($items ?? [])
            ->filter(fn (mixed $item): bool => filled(data_get($item, 'path')))
            ->map(fn (mixed $item): array => [
                'type' => data_get($item, 'type'),
                'url' => (string) self::url(data_get($item, 'path')),
                'thumbnail_url' => self::url(data_get($item, 'thumbnail_path')),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $content
     * @return array<string, mixed>|null
     */
    public static function contentUrls(?array $content): ?array
    {
        if (blank($content)) {
            return null;
        }

        $files = array_filter([
            'url' => self::url(data_get($content, 'path')),
            'thumbnail_url' => self::url(data_get($content, 'thumbnail_path')),
        ], fn (?string $url): bool => filled($url));

        return [...collect($content)->except(['path', 'thumbnail_path', 'thumbnail_url'])->all(), ...$files];
    }

    /**
     * Turns the links a network read brings into stored values: a file
     * already on our disk keeps its path, a network link leaves its slot
     * empty and waits in `meta.pending_media`.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function fromNetwork(array $values): array
    {
        $pending = [];
        $toPath = function (string $slot, mixed $url) use (&$pending): ?string {
            if (! is_string($url) || blank($url)) {
                return null;
            }

            if (self::isNetworkHosted($url)) {
                $pending[self::markerKey($slot)] = $url;

                return null;
            }

            return self::ownPath($url);
        };

        foreach (['author_avatar', 'participant_avatar'] as $avatar) {
            if (array_key_exists("{$avatar}_url", $values)) {
                $values["{$avatar}_path"] = $toPath("{$avatar}_path", data_get($values, "{$avatar}_url"));
                unset($values["{$avatar}_url"]);
            }
        }

        foreach (['media', 'subject_media'] as $key) {
            if (is_array(data_get($values, $key))) {
                $values[$key] = collect($values[$key])
                    ->map(fn (mixed $item, int|string $index): array => array_filter([
                        'type' => data_get($item, 'type'),
                        'path' => $toPath("{$key}.{$index}.path", data_get($item, 'url')),
                        'thumbnail_path' => $toPath("{$key}.{$index}.thumbnail_path", data_get($item, 'thumbnail_url')),
                    ], fn (mixed $value): bool => filled($value)))
                    ->all();
            }
        }

        $content = data_get($values, 'meta.content');

        if (is_array($content)) {
            $isLink = data_get($content, 'type') === DirectMessageContent::SharedPost->value;
            $values['meta']['content'] = array_filter([
                ...collect($content)->except(['thumbnail_url', ...($isLink ? [] : ['url'])])->all(),
                'path' => $isLink ? null : $toPath('meta.content.path', data_get($content, 'url')),
                'thumbnail_path' => $toPath('meta.content.thumbnail_path', data_get($content, 'thumbnail_url')),
            ], fn (mixed $value): bool => filled($value));
        }

        if ($pending !== []) {
            $values['meta'] = [...(array) data_get($values, 'meta', []), self::PENDING => $pending];
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public static function source(InboxMessage|InboxConversation $model): array
    {
        return $model instanceof InboxMessage
            ? ['author_avatar_path' => $model->author_avatar_path, 'media' => $model->media ?? [], 'meta' => $model->meta ?? []]
            : ['participant_avatar_path' => $model->participant_avatar_path, 'subject_media' => $model->subject_media ?? [], 'meta' => $model->meta ?? []];
    }

    /**
     * The network links still waiting for a copy, keyed by slot. A
     * conversation linked to a post shows the post's own media, so its
     * subject media is never copied.
     *
     * @return array<string, string>
     */
    public static function pending(InboxMessage|InboxConversation $model): array
    {
        $source = self::source($model);
        $links = (array) data_get($source, 'meta.'.self::PENDING, []);

        return collect(self::slots($model, $source))
            ->reject(fn (string $slot): bool => filled(self::markerOf($source, $slot)))
            ->mapWithKeys(fn (string $slot): array => [$slot => data_get($links, self::markerKey($slot))])
            ->filter(fn (mixed $url): bool => self::isNetworkHosted($url))
            ->all();
    }

    /**
     * Whether a message's media or content is still being copied to our
     * storage: until then it has nothing to show, which is not a message the
     * network failed to deliver.
     */
    public static function hasPendingContent(InboxMessage $message): bool
    {
        return collect(self::pending($message))
            ->keys()
            ->contains(fn (string $slot): bool => str_starts_with($slot, 'media.') || str_starts_with($slot, 'meta.content.'));
    }

    /**
     * @return list<MediaType>
     */
    public static function allowedTypes(InboxMessage|InboxConversation $model, string $slot): array
    {
        if (str_starts_with($slot, 'meta.content.')) {
            return MediaType::cases();
        }

        if (str_ends_with($slot, 'avatar_path') || str_ends_with($slot, '.thumbnail_path')) {
            return [MediaType::Image];
        }

        $type = MediaType::tryFrom((string) data_get(self::source($model), self::parent($slot).'.type'));

        return $type ? [$type] : [MediaType::Image, MediaType::Video];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function markerOf(array $source, string $slot): ?string
    {
        $marker = data_get($source, 'meta.'.self::MARKERS.'.'.self::markerKey($slot));

        return is_string($marker) ? $marker : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public static function pendingLinkOf(array $source, string $slot): ?string
    {
        $link = data_get($source, 'meta.'.self::PENDING.'.'.self::markerKey($slot));

        return is_string($link) ? $link : null;
    }

    /**
     * Records how a slot was handled and drops its pending link.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    public static function withMarker(array $source, string $slot, string $marker): array
    {
        $meta = (array) data_get($source, 'meta', []);
        $meta[self::MARKERS] = [...(array) data_get($meta, self::MARKERS, []), self::markerKey($slot) => $marker];
        $pending = collect((array) data_get($meta, self::PENDING, []))->except(self::markerKey($slot))->all();
        unset($meta[self::PENDING]);
        $source['meta'] = $pending === [] ? $meta : [...$meta, self::PENDING => $pending];

        return $source;
    }

    /**
     * A later read of a row brings the network's links again, freshly
     * signed. A handled slot keeps what it shows (the copy, or nothing once
     * the file could not be copied) while the read still has the same item;
     * anything else replaces it and drops its marker. Links still waiting
     * from an earlier read stay until a newer one replaces them.
     *
     * @param  array<string, mixed>  $values  stored values, from fromNetwork()
     * @return array<string, mixed>
     */
    public static function keepStored(InboxMessage|InboxConversation $model, array $values): array
    {
        $stored = self::source($model);
        $incomingKeys = array_keys(array_intersect_key($values, $stored));
        $final = [...$stored, ...array_intersect_key($values, $stored)];
        $markers = (array) data_get($stored, 'meta.'.self::MARKERS, []);
        $pending = [...(array) data_get($stored, 'meta.'.self::PENDING, []), ...(array) data_get($values, 'meta.'.self::PENDING, [])];
        $kept = [];

        foreach ($markers as $key => $marker) {
            $slot = str_replace(':', '.', (string) $key);
            $storedValue = data_get($stored, $slot);

            if (! self::sameItem($stored, $final, $slot)) {
                continue;
            }

            if (data_get($final, $slot) !== $storedValue) {
                if (filled(data_get($final, $slot)) || ! array_key_exists($key, $pending)) {
                    continue;
                }

                data_set($final, $slot, $storedValue);
            }

            $kept[$key] = $marker;
            unset($pending[$key]);
        }

        $slots = collect(self::slots($model, $final))->map(fn (string $slot): string => self::markerKey($slot))->all();
        $pending = array_intersect_key($pending, array_flip($slots));

        $meta = collect((array) data_get($final, 'meta', []))->except([self::MARKERS, self::PENDING])->all();
        $final['meta'] = array_filter([...$meta, self::MARKERS => $kept, self::PENDING => $pending], fn (mixed $value): bool => $value !== []);

        return [...$values, ...array_intersect_key($final, array_flip([...$incomingKeys, 'meta']))];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private static function slots(InboxMessage|InboxConversation $model, array $source): array
    {
        return $model instanceof InboxMessage
            ? ['author_avatar_path', ...self::itemSlots($source, 'media'), 'meta.content.path', 'meta.content.thumbnail_path']
            : ['participant_avatar_path', ...(blank($model->post_id) ? self::itemSlots($source, 'subject_media') : [])];
    }

    private static function ownPath(string $url): ?string
    {
        $storageUrl = rtrim(Storage::url(''), '/');

        return filled($storageUrl) && str_starts_with($url, "{$storageUrl}/") ? Str::after($url, "{$storageUrl}/") : null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private static function itemSlots(array $source, string $key): array
    {
        return collect((array) data_get($source, $key, []))
            ->keys()
            ->flatMap(fn (int|string $index): array => ["{$key}.{$index}.path", "{$key}.{$index}.thumbnail_path"])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $final
     */
    private static function sameItem(array $stored, array $final, string $slot): bool
    {
        $parent = self::parent($slot);

        return $parent === '' || data_get($stored, "{$parent}.type") === data_get($final, "{$parent}.type");
    }

    private static function parent(string $slot): string
    {
        return Str::contains($slot, '.') ? Str::beforeLast($slot, '.') : '';
    }

    private static function markerKey(string $slot): string
    {
        return str_replace('.', ':', $slot);
    }
}
