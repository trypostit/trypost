<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Post\Origin;
use App\Enums\SocialAccount\Platform;
use App\Jobs\ResolveTikTokVideoId;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Services\Social\TikTokPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('tiktok:repair-video-ids {--dry-run : Run the repairs and roll them back, listing what would change}')]
#[Description('Give TikTok posts back the videos a caption match handed to other posts, and settle videos several posts hold')]
class RepairTikTokVideoIdsCommand extends Command
{
    /** TikTok creates the video about half a minute before the publish completes. */
    private const int CREATE_TO_PUBLISH_MINUTES = 2;

    /** A slow publish can finish many minutes after the video was created, so this alone proves nothing. */
    private const int LATE_VIDEO_MINUTES = 10;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $repairs = $this->repairs();
            $deletedWithRepairs = $this->applyRepairs($repairs);
            $settled = $this->settleSharedVideos();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        $this->table(
            ['Post', 'Published at', 'Held video', 'Own video'],
            $repairs->map(fn (array $repair): array => [
                data_get($repair, 'post.id'),
                data_get($repair, 'post.published_at')->toDateTimeString(),
                data_get($repair, 'held.remote_id'),
                data_get($repair, 'own.remote_id'),
            ])->all(),
        );

        $this->table(
            ['Video', 'Kept by', 'Got their own video back', 'Released posts', 'Deleted imported copies'],
            $settled->map(fn (array $video): array => [
                data_get($video, 'video.remote_id'),
                data_get($video, 'owner.id'),
                data_get($video, 'returned')->pluck('id')->implode(', ') ?: '-',
                data_get($video, 'released')->pluck('id')->implode(', ') ?: '-',
                data_get($video, 'copies'),
            ])->all(),
        );

        $awaiting = Post::query()
            ->publishedToTikTok()
            ->whereHas('socialAccount', fn (Builder $query): Builder => $query->connected())
            ->lazyById()
            ->filter(fn (Post $post): bool => $post->awaitsTikTokVideoId())
            ->collect();

        $returned = $repairs->count() + $settled->sum(fn (array $video): int => data_get($video, 'returned')->count());
        $released = $settled->sum(fn (array $video): int => data_get($video, 'released')->count());
        $copies = $deletedWithRepairs + $settled->sum(fn (array $video): int => data_get($video, 'copies'));

        if ($dryRun) {
            $this->info("{$returned} post(s) would get their own video back; {$released} post(s) would lose a video another post owns; {$copies} imported copy(ies) would be deleted; {$awaiting->count()} post(s) would ask TikTok for their video id.");

            return self::SUCCESS;
        }

        $awaiting->each(fn (Post $post) => ResolveTikTokVideoId::dispatch($post));

        $this->info("{$returned} post(s) got their own video back; {$released} post(s) lost a video another post owns; {$copies} imported copy(ies) deleted; {$awaiting->count()} post(s) are asking TikTok for their video id.");

        return self::SUCCESS;
    }

    /**
     * A post holds the wrong video when that video was created right before
     * another post of the channel was published, and one video was created
     * right before the post itself.
     *
     * @return Collection<int, array{post: Post, held: AnalyticsPublication, own: AnalyticsPublication}>
     */
    private function repairs(): Collection
    {
        $repairs = AnalyticsPublication::query()
            ->where('network', Platform::TikTok->network())
            ->whereHas('post', fn (Builder $query): Builder => $query->publishedToTikTok()->whereNotNull('published_at')->has('socialAccount'))
            ->with('post.socialAccount')
            ->lazyById()
            ->filter(fn (AnalyticsPublication $held): bool => ctype_digit($held->remote_id)
                && $held->provider_published_at->lessThan($held->post->published_at->subMinutes(self::LATE_VIDEO_MINUTES))
                && $this->publishedRightAfter($held))
            ->map(fn (AnalyticsPublication $held): array => [
                'post' => $held->post,
                'held' => $held,
                'own' => $this->videoCreatedRightBefore($held->post, $held),
            ])
            ->filter(fn (array $repair): bool => filled(data_get($repair, 'own')))
            ->values()
            ->collect();

        do {
            $before = $repairs->count();
            $repairs = $this->withFreeOwnVideo($repairs);
        } while ($repairs->count() !== $before);

        return $repairs;
    }

    /**
     * @param  Collection<int, array{post: Post, held: AnalyticsPublication, own: AnalyticsPublication}>  $repairs
     * @return int imported copies deleted
     */
    private function applyRepairs(Collection $repairs): int
    {
        $repairs->each(fn (array $repair) => data_get($repair, 'held')->update([
            'post_id' => null,
            'origin' => PublicationOrigin::External,
        ]));

        return $repairs->filter(fn (array $repair): bool => $this->giveBack(data_get($repair, 'post'), data_get($repair, 'own')->fresh()))->count();
    }

    /**
     * Keeps a repair only when its own video is claimed by no other repair and
     * is free once the repairs run: unlinked, imported, or held by a post that
     * is itself repaired. Dropping one repair can strand another, so the caller
     * repeats this until nothing changes.
     *
     * @param  Collection<int, array{post: Post, held: AnalyticsPublication, own: AnalyticsPublication}>  $repairs
     * @return Collection<int, array{post: Post, held: AnalyticsPublication, own: AnalyticsPublication}>
     */
    private function withFreeOwnVideo(Collection $repairs): Collection
    {
        $repairedPostIds = $repairs->map(fn (array $repair): string => data_get($repair, 'post.id'));
        $claims = $repairs->countBy(fn (array $repair): string => data_get($repair, 'own.id'));

        return $repairs
            ->filter(fn (array $repair): bool => $claims->get(data_get($repair, 'own.id')) === 1
                && (blank(data_get($repair, 'own.post_id'))
                    || $repairedPostIds->contains(data_get($repair, 'own.post_id'))
                    || Post::query()->imported()->whereKey(data_get($repair, 'own.post_id'))->exists()))
            ->values();
    }

    private function publishedRightAfter(AnalyticsPublication $held): bool
    {
        $createdAt = $held->provider_published_at;

        return Post::query()
            ->createdInTryPost()
            ->where('social_account_id', $held->post->social_account_id)
            ->whereKeyNot($held->post_id)
            ->whereBetween('published_at', [$createdAt, $createdAt->addMinutes(self::CREATE_TO_PUBLISH_MINUTES)])
            ->exists();
    }

    /**
     * The one video of the channel created in the two minutes before the post
     * was published, or null when there is none or more than one.
     */
    private function videoCreatedRightBefore(Post $post, AnalyticsPublication $sameChannel): ?AnalyticsPublication
    {
        $publishedAt = $post->published_at;

        $candidates = AnalyticsPublication::query()
            ->where('workspace_id', $sameChannel->workspace_id)
            ->where('social_account_key', $sameChannel->social_account_key)
            ->where('network', $sameChannel->network)
            ->whereBetween('provider_published_at', [$publishedAt->subMinutes(self::CREATE_TO_PUBLISH_MINUTES), $publishedAt])
            ->limit(2)
            ->get();

        return $candidates->count() === 1 && ctype_digit($candidates->sole()->remote_id) ? $candidates->sole() : null;
    }

    /**
     * @return bool whether an imported copy of the video was deleted
     */
    private function giveBack(Post $post, AnalyticsPublication $own): bool
    {
        $imported = filled($own->post_id) ? Post::query()->imported()->whereKey($own->post_id)->first() : null;

        if (filled($imported)) {
            DeleteOwnedMedia::forPosts([$imported->id]);
            Post::withoutEvents(fn (): ?bool => $imported->delete());
        }

        $own->update(['post_id' => $post->id, 'origin' => PublicationOrigin::TryPost]);

        $post->writePublication([
            'platform_post_id' => $own->remote_id,
            'platform_url' => $own->permalink
                ?? TikTokPublisher::postUrl($post->socialAccount, $own->remote_id)
                ?? $post->platform_url,
        ]);

        return filled($imported);
    }

    /**
     * Every video id more than one post of a channel holds. The TryPost post
     * published right after the video was created, with no other video in that
     * window, keeps it. Each other TryPost post gets its own video back when
     * exactly one free video was created right before it, and otherwise loses
     * the id and points at the profile again; imported copies are deleted. A
     * video without exactly one such owner is left alone.
     *
     * @return Collection<int, array{video: AnalyticsPublication, owner: Post, returned: Collection<int, Post>, released: Collection<int, Post>, copies: int}>
     */
    private function settleSharedVideos(): Collection
    {
        return Post::query()
            ->where('platform', Platform::TikTok)
            ->where(fn (Builder $query): Builder => $query->imported()
                ->orWhere(fn (Builder $published): Builder => $published->createdInTryPost()->publicationPublished()))
            ->has('socialAccount')
            ->whereNotNull('platform_post_id')
            ->with('socialAccount')
            ->lazyById()
            ->filter(fn (Post $post): bool => ctype_digit((string) $post->platform_post_id))
            ->groupBy(fn (Post $post): string => "{$post->social_account_id}:{$post->platform_post_id}")
            ->filter(fn (Collection $posts): bool => $posts->count() > 1)
            ->map(fn (Collection $posts): ?array => $this->settle($posts))
            ->filter()
            ->values()
            ->collect();
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @return array{video: AnalyticsPublication, owner: Post, returned: Collection<int, Post>, released: Collection<int, Post>, copies: int}|null
     */
    private function settle(Collection $posts): ?array
    {
        $sample = $posts->first();
        $videos = AnalyticsPublication::query()
            ->where('workspace_id', $sample->workspace_id)
            ->where('social_account_id', $sample->social_account_id)
            ->where('network', Platform::TikTok->network())
            ->where('remote_id', $sample->platform_post_id)
            ->limit(2)
            ->get();

        if ($videos->count() !== 1) {
            return null;
        }

        $video = $videos->sole();
        $createdAt = $video->provider_published_at;
        $owners = $posts->filter(fn (Post $post): bool => $post->origin === Origin::TryPost
            && filled($post->published_at)
            && $post->published_at->betweenIncluded($createdAt, $createdAt->addMinutes(self::CREATE_TO_PUBLISH_MINUTES)));

        if ($owners->count() !== 1) {
            return null;
        }

        $owner = $owners->sole();
        $ownerVideo = $this->videoCreatedRightBefore($owner, $video);

        if (blank($ownerVideo) || ! $ownerVideo->is($video)) {
            return null;
        }

        if ($video->post_id !== $owner->id && AnalyticsPublication::query()->where('post_id', $owner->id)->exists()) {
            return null;
        }

        $others = $posts->reject(fn (Post $post): bool => $post->is($owner))->values();
        $copies = $others->filter(fn (Post $post): bool => $post->origin === Origin::Network)->values();

        $video->update(['post_id' => $owner->id, 'origin' => PublicationOrigin::TryPost]);

        if ($copies->isNotEmpty()) {
            DeleteOwnedMedia::forPosts($copies->pluck('id')->all());
            Post::query()->whereKey($copies->pluck('id')->all())->delete();
        }

        $returned = new Collection;
        $released = new Collection;
        $deletedWithReturns = 0;

        $others->filter(fn (Post $post): bool => $post->origin === Origin::TryPost)->each(function (Post $post) use ($video, $returned, $released, &$deletedWithReturns): void {
            $own = filled($post->published_at) ? $this->videoCreatedRightBefore($post, $video) : null;

            if (filled($own) && ! $own->is($video) && (blank($own->post_id) || Post::query()->imported()->whereKey($own->post_id)->exists())) {
                $deletedWithReturns += (int) $this->giveBack($post, $own);
                $returned->push($post);

                return;
            }

            $post->writePublication([
                'platform_post_id' => null,
                'platform_url' => TikTokPublisher::postUrl($post->socialAccount) ?? $post->platform_url,
            ]);
            $released->push($post);
        });

        return [
            'video' => $video,
            'owner' => $owner,
            'returned' => $returned,
            'released' => $released,
            'copies' => $copies->count() + $deletedWithReturns,
        ];
    }
}
