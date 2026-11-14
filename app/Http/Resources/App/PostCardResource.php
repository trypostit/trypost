<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
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
     * Keyed by channel id. A card alone keeps its own; the cards of one
     * serializer() share it.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $serializedAccounts = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $post = $this->resource;

        return [
            ...$post->attributesToArray(),
            ...$this->otherRelations($post),
            'social_account' => $this->whenLoaded('socialAccount', fn (SocialAccount $account): array => $this->accountArray($account)),
            'user' => $this->whenLoaded('user', fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'photo_url' => $user->photo_url,
            ]),
            'failure' => transform($post->error_context, fn (array $context): array => [
                'category' => data_get($context, 'category'),
                'failed_at' => data_get($context, 'failed_at'),
            ]),
        ];
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
     * The post's loaded relations as `toArray()` writes them (its labels…),
     * except its channel and its author, which the card writes itself.
     *
     * @return array<string, mixed>
     */
    private function otherRelations(Post $post): array
    {
        $post->makeHidden(['socialAccount', 'user']);
        $relations = $post->relationsToArray();
        $post->makeVisible(['socialAccount', 'user']);

        return $relations;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountArray(SocialAccount $account): array
    {
        return $this->serializedAccounts[$account->id] ??= $account->toArray();
    }
}
