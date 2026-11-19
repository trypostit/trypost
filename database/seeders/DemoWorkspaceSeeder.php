<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Account;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A demo workspace for the release thumbnails (`.claude/release-assets/render-thumbnail.ts`),
 * which signs in as `demo@trypost.it` and shoots the screen a release is about. It holds the
 * site's demo brand (John Doe, Doe Media): ten channels, three tags and posts in every column of
 * the board, dated around today. Not part of DatabaseSeeder: run it locally only, and remove it
 * with `forget()` once the shot is taken, so the scheduler never picks its posts up.
 *
 * Media and avatars point at DEMO_MEDIA_URL, which the render script answers from
 * `.claude/release-assets/demo-media`, so nothing is uploaded to a disk.
 */
class DemoWorkspaceSeeder extends Seeder
{
    public const string EMAIL = 'demo@trypost.it';

    public const string PASSWORD = 'password';

    public const string DEMO_MEDIA_URL = 'https://demo-media.trypost.invalid';

    public function run(): void
    {
        self::forget();

        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'timezone' => 'America/New_York',
        ]);
        $user->account->update(['name' => 'Doe Media']);
        $user->account->subscriptions()->create([
            'type' => Account::SUBSCRIPTION_NAME,
            'stripe_id' => "sub_demo_{$user->id}",
            'stripe_status' => 'active',
            'stripe_price' => 'price_demo',
        ]);

        $workspace = Workspace::factory()->create([
            'user_id' => $user->id,
            'account_id' => $user->account_id,
            'name' => 'Doe Media',
        ]);
        $workspace->members()->attach($user->id, ['is_admin' => true, 'requires_approval' => false]);
        $user->update(['current_workspace_id' => $workspace->id]);

        $channels = $this->channels($workspace);
        $labels = $this->labels($workspace);

        foreach ($this->posts() as $post) {
            $this->createPost($workspace, $user, $channels, $labels, $post);
        }

        $this->command?->info('Demo workspace ready: '.self::EMAIL.' / '.self::PASSWORD);
    }

    /**
     * Removes the demo user, its account and everything in its workspaces.
     */
    public static function forget(): void
    {
        $user = User::query()->where('email', self::EMAIL)->first();

        if (blank($user)) {
            return;
        }

        $account = $user->account;
        $account?->workspaces()->each(fn (Workspace $workspace) => $workspace->delete());
        $user->delete();
        $account?->delete();
    }

    /**
     * @return array<string, SocialAccount>
     */
    private function channels(Workspace $workspace): array
    {
        $networks = [
            'instagram' => ['instagram', 'doe.media'],
            'linkedin' => ['linkedin', 'Doe Media'],
            'x' => ['x', 'doemedia'],
            'tiktok' => ['tiktok', 'doe.media'],
            'youtube' => ['youtube', 'Doe Media'],
            'threads' => ['threads', 'doe.media'],
            'bluesky' => ['bluesky', 'doemedia.bsky.social'],
            'mastodon' => ['mastodon', 'doemedia@mastodon.social'],
            'facebook' => ['facebook', 'Doe Media'],
            'pinterest' => ['pinterest', 'doemedia'],
        ];

        $channels = [];
        $position = 1;
        foreach ($networks as $key => [$state, $username]) {
            $channels[$key] = SocialAccount::factory()->{$state}()->create([
                'workspace_id' => $workspace->id,
                'display_name' => 'John Doe',
                'username' => $username,
                'avatar_url' => self::DEMO_MEDIA_URL.'/persona.svg',
                'position' => $position++,
                'timezone' => 'America/New_York',
            ]);
        }

        return $channels;
    }

    /**
     * @return array<string, WorkspaceLabel>
     */
    private function labels(Workspace $workspace): array
    {
        $labels = [];
        foreach (['Launch' => '#DDA0DD', 'Behind the scenes' => '#89CFF0', 'Promo' => '#FFD580'] as $name => $color) {
            $labels[$name] = WorkspaceLabel::factory()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'color' => $color,
            ]);
        }

        return $labels;
    }

    /**
     * Each post: channel, status, day offset from today and hour, text, photo and tags.
     *
     * @return list<array{channel: string, status: PostStatus, day: int, hour: int, text: string, photo: ?string, labels: list<string>}>
     */
    private function posts(): array
    {
        return [
            ['channel' => 'instagram', 'status' => PostStatus::Draft, 'day' => 3, 'hour' => 9, 'text' => 'New mugs, same morning ritual. Which glaze is your favorite?', 'photo' => 'mugs.jpg', 'labels' => ['Launch']],
            ['channel' => 'linkedin', 'status' => PostStatus::Draft, 'day' => 4, 'hour' => 11, 'text' => 'Three things we learned shipping our first ceramics collection.', 'photo' => null, 'labels' => ['Behind the scenes']],
            ['channel' => 'pinterest', 'status' => PostStatus::Draft, 'day' => 5, 'hour' => 15, 'text' => 'Moodboard for the spring collection: soft clay, warm light.', 'photo' => 'moodboard.jpg', 'labels' => []],
            ['channel' => 'tiktok', 'status' => PostStatus::PendingApproval, 'day' => 1, 'hour' => 18, 'text' => 'From a lump of clay to your morning latte, in 30 seconds.', 'photo' => 'wheel.jpg', 'labels' => ['Behind the scenes']],
            ['channel' => 'facebook', 'status' => PostStatus::PendingApproval, 'day' => 2, 'hour' => 10, 'text' => 'Weekend market this Saturday: come say hi and try a cup.', 'photo' => 'shelf.jpg', 'labels' => ['Promo']],
            ['channel' => 'instagram', 'status' => PostStatus::Scheduled, 'day' => 1, 'hour' => 9, 'text' => 'Latte art Friday. Tag a friend who needs a coffee.', 'photo' => 'latte.jpg', 'labels' => ['Promo']],
            ['channel' => 'x', 'status' => PostStatus::Scheduled, 'day' => 1, 'hour' => 12, 'text' => 'The spring collection drops Monday. Set a reminder.', 'photo' => null, 'labels' => ['Launch']],
            ['channel' => 'youtube', 'status' => PostStatus::Scheduled, 'day' => 2, 'hour' => 14, 'text' => 'How we glaze a mug, start to finish', 'photo' => 'shoot.jpg', 'labels' => ['Behind the scenes']],
            ['channel' => 'threads', 'status' => PostStatus::Scheduled, 'day' => 3, 'hour' => 11, 'text' => 'Coffee first, emails later. What is your morning order?', 'photo' => null, 'labels' => []],
            ['channel' => 'instagram', 'status' => PostStatus::Published, 'day' => -1, 'hour' => 9, 'text' => 'Our studio shelf is officially full. Thank you for an amazing month.', 'photo' => 'shelf.jpg', 'labels' => []],
            ['channel' => 'linkedin', 'status' => PostStatus::Published, 'day' => -1, 'hour' => 13, 'text' => 'We are hiring a part-time studio assistant. Know someone?', 'photo' => 'laptop.jpg', 'labels' => []],
            ['channel' => 'bluesky', 'status' => PostStatus::Published, 'day' => -2, 'hour' => 10, 'text' => 'Fresh batch out of the kiln this morning.', 'photo' => 'mug.jpg', 'labels' => ['Behind the scenes']],
            ['channel' => 'mastodon', 'status' => PostStatus::Failed, 'day' => -1, 'hour' => 16, 'text' => 'Spring collection preview, now with photos.', 'photo' => 'mugs.jpg', 'labels' => ['Launch']],
        ];
    }

    /**
     * @param  array<string, SocialAccount>  $channels
     * @param  array<string, WorkspaceLabel>  $labels
     * @param  array{channel: string, status: PostStatus, day: int, hour: int, text: string, photo: ?string, labels: list<string>}  $post
     */
    private function createPost(Workspace $workspace, User $user, array $channels, array $labels, array $post): void
    {
        $channel = $channels[$post['channel']];
        $at = CarbonImmutable::now('America/New_York')->startOfDay()->addDays($post['day'])->setHour($post['hour'])->utc();

        $factory = Post::factory()->forAccount($channel, ContentType::defaultFor($channel->platform));
        $factory = match ($post['status']) {
            PostStatus::Draft => $factory->draft(),
            PostStatus::PendingApproval => $factory->pendingApproval(),
            PostStatus::Scheduled => $factory->scheduled(),
            PostStatus::Published => $factory->published(),
            PostStatus::Failed => $factory->failed(),
        };

        $created = $factory->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'content' => "<p>{$post['text']}</p>",
            'media' => $this->media($post['photo']),
            'scheduled_at' => $at,
            'published_at' => $post['status'] === PostStatus::Published ? $at : null,
            'meta' => $this->meta($channel->platform, $post['text']),
        ]);

        $created->labels()->attach(collect($post['labels'])->map(fn (string $name) => $labels[$name]->id)->all());
    }

    /**
     * @return list<array<string, string>>
     */
    private function media(?string $photo): array
    {
        if (blank($photo)) {
            return [];
        }

        return [[
            'id' => (string) Str::uuid(),
            'path' => "demo/{$photo}",
            'url' => self::DEMO_MEDIA_URL."/{$photo}",
            'type' => 'image',
            'mime_type' => 'image/jpeg',
        ]];
    }

    /**
     * @return array<string, string>
     */
    private function meta(Platform $platform, string $text): array
    {
        if ($platform === Platform::YouTube) {
            return ['title' => $text];
        }

        return [];
    }
}
