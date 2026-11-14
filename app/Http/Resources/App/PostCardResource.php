<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Post;
use App\Models\SocialAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A post in the shape the publish page cards read (see BuildPublishPageProps::decorate).
 * Its author carries only what a card shows, never the member's account data.
 *
 * @mixin Post
 */
class PostCardResource extends JsonResource
{
    /**
     * The channels already serialized in this batch of cards, so a page of
     * posts on a few channels serializes each channel once.
     *
     * Keyed by channel id; null outside a batch, where each card serializes
     * its own channel (an empty array is a batch that has none yet).
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $serializedAccounts = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $post = $this->resource;

        $card = [
            ...$post->attributesToArray(),
            ...$this->relations($post),
            'failure' => $post->error_context === null ? null : [
                'category' => data_get($post->error_context, 'category'),
                'failed_at' => data_get($post->error_context, 'failed_at'),
            ],
        ];

        if ($post->relationLoaded('user')) {
            $card['user'] = $post->user === null ? null : [
                'id' => $post->user->id,
                'name' => $post->user->name,
                'photo_url' => $post->user->photo_url,
            ];
        }

        return $card;
    }

    /**
     * @param  iterable<int, Post>  $posts
     * @return list<array<string, mixed>>
     */
    public static function cards(iterable $posts): array
    {
        return collect($posts)->map(self::serializer())->values()->all();
    }

    /**
     * Resolves cards one by one while sharing the work between them: each
     * channel is serialized once for every card that uses this serializer.
     *
     * @return Closure(Post): array<string, mixed>
     */
    public static function serializer(): Closure
    {
        $accounts = [];

        return function (Post $post) use (&$accounts): array {
            $resource = self::make($post);
            $resource->serializedAccounts = &$accounts;

            return $resource->resolve();
        };
    }

    /**
     * The post's loaded relations as `toArray()` writes them, without its
     * author (the card writes its own) and with its channel serialized once.
     *
     * @return array<string, mixed>
     */
    private function relations(Post $post): array
    {
        $hidden = $post->getHidden();
        $post->setHidden([...$hidden, 'user', 'socialAccount']);
        $relations = $post->relationsToArray();
        $post->setHidden($hidden);

        if ($post->relationLoaded('socialAccount') && collect($hidden)->doesntContain('socialAccount')) {
            $relations['social_account'] = transform(
                $post->socialAccount,
                fn (SocialAccount $account): array => $this->accountArray($account),
            );
        }

        return $relations;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountArray(SocialAccount $account): array
    {
        if ($this->serializedAccounts === null) {
            return $account->toArray();
        }

        return $this->serializedAccounts[$account->id] ??= $account->toArray();
    }
}
