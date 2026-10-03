<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->instagram = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);
});

/**
 * @param  list<string>  $files
 * @param  array<string, mixed>  $attributes
 */
function gridPost(SocialAccount $account, string $publishedAt, ContentType $contentType = ContentType::InstagramFeed, array $files = ['photo.jpg'], array $attributes = [], bool $imported = false): PostPlatform
{
    $post = Post::factory()
        ->when($imported, fn ($factory) => $factory->imported(), fn ($factory) => $factory->published())
        ->create([
            'workspace_id' => $account->workspace_id,
            'published_at' => $publishedAt,
            'media' => array_map(fn (string $file): array => [
                'id' => (string) str()->uuid(),
                'path' => "media/{$file}",
                'url' => "https://cdn.example.com/{$file}",
                'type' => match (pathinfo($file, PATHINFO_EXTENSION)) {
                    'mp4' => 'video',
                    'pdf' => 'document',
                    default => 'image',
                },
                'mime_type' => match (pathinfo($file, PATHINFO_EXTENSION)) {
                    'mp4' => 'video/mp4',
                    'pdf' => 'application/pdf',
                    default => 'image/jpeg',
                },
                ...(str_ends_with($file, '.mp4') ? ['meta' => ['cover_offset_ms' => 1500]] : []),
            ], $files),
        ]);

    return PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => $contentType,
        'published_at' => $publishedAt,
        ...$attributes,
    ]);
}

test('the grid lists published feed posts and reels newest first, without stories', function () {
    $older = gridPost($this->instagram, '2026-09-01 10:00:00');
    $carousel = gridPost($this->instagram, '2026-09-10 10:00:00', files: ['a.jpg', 'deck.pdf', 'b.mp4']);
    $reel = gridPost($this->instagram, '2026-09-20 10:00:00', ContentType::InstagramReel, ['clip.mp4']);
    $imported = gridPost($this->instagram, '2026-09-15 10:00:00', imported: true);
    gridPost($this->instagram, '2026-09-25 10:00:00', ContentType::InstagramStory);
    gridPost($this->instagram, '2026-09-26 10:00:00', attributes: ['status' => Status::Failed]);
    gridPost($this->instagram, '2026-09-27 10:00:00', attributes: ['status' => Status::Pending, 'published_at' => null]);
    gridPost($this->instagram, '2026-09-28 10:00:00', attributes: ['enabled' => false]);

    $this->actingAs($this->user)
        ->get(route('app.channels.grid', $this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels/Grid')
            ->where('channel.id', $this->instagram->id)
            ->where('channel.has_grid', true)
            ->has('posts.data', 4)
            ->where('posts.data.0.id', $reel->post_id)
            ->where('posts.data.0.kind', 'reel')
            ->has('posts.data.0.items', 1)
            ->where('posts.data.0.items.0.type', 'video')
            ->where('posts.data.0.items.0.mime_type', 'video/mp4')
            ->where('posts.data.0.items.0.url', 'https://cdn.example.com/clip.mp4')
            ->where('posts.data.0.items.0.meta.cover_offset_ms', 1500)
            ->where('posts.data.1.id', $imported->post_id)
            ->where('posts.data.1.kind', 'single')
            ->where('posts.data.2.id', $carousel->post_id)
            ->where('posts.data.2.kind', 'carousel')
            ->has('posts.data.2.items', 2)
            ->where('posts.data.2.items.0.url', 'https://cdn.example.com/a.jpg')
            ->where('posts.data.2.items.0.type', 'image')
            ->where('posts.data.2.items.1.url', 'https://cdn.example.com/b.mp4')
            ->where('posts.data.2.items.1.type', 'video')
            ->where('posts.data.3.id', $older->post_id)
            ->has('posts.data.3.items', 1)
            ->where('posts.data.3.items.0.type', 'image')
        );
});

test('a single feed video is shown as a reel and a post without media has no cover', function () {
    $video = gridPost($this->instagram, '2026-09-10 10:00:00', files: ['clip.mp4']);
    $bare = gridPost($this->instagram, '2026-09-01 10:00:00', files: []);

    $this->actingAs($this->user)
        ->get(route('app.channels.grid', $this->instagram))
        ->assertInertia(fn (Assert $page) => $page
            ->where('posts.data.0.id', $video->post_id)
            ->where('posts.data.0.kind', 'reel')
            ->where('posts.data.1.id', $bare->post_id)
            ->where('posts.data.1.items', [])
        );
});

test('the grid only shows the requested channel', function () {
    $other = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);
    gridPost($other, '2026-09-10 10:00:00');
    $mine = gridPost($this->instagram, '2026-09-01 10:00:00');

    $this->actingAs($this->user)
        ->get(route('app.channels.grid', $this->instagram))
        ->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $mine->post_id)
        );
});

test('instagram through facebook has a grid too', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.grid', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('channels/Grid')->has('posts.data', 0));
});

test('channels of other networks have no grid', function (Platform $platform) {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => $platform,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.grid', $account))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $account))
        ->assertInertia(fn (Assert $page) => $page->where('channel.has_grid', false));
})->with([Platform::LinkedIn, Platform::Facebook, Platform::TikTok, Platform::Threads]);

test('a channel from another workspace is not found', function () {
    $foreign = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $this->actingAs($this->user)
        ->get(route('app.channels.grid', $foreign))
        ->assertNotFound();
});

test('guests are sent to login', function () {
    $this->get(route('app.channels.grid', $this->instagram))
        ->assertRedirect(route('login'));
});

test('members whose posts need approval can view the grid', function () {
    $this->actingAs(workspaceMember($this->workspace, 'approval'))
        ->get(route('app.channels.grid', $this->instagram))
        ->assertOk();
});

test('the grid page size comes from the pagination config', function () {
    config()->set('app.pagination.default', 2);

    foreach (range(1, 3) as $day) {
        gridPost($this->instagram, "2026-09-0{$day} 10:00:00");
    }

    $this->actingAs($this->user)
        ->get(route('app.channels.grid', $this->instagram))
        ->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 2)
            ->where('posts.meta.per_page', 2)
            ->where('posts.meta.total', 3)
        );
});

test('the grid query count does not grow with the number of posts', function () {
    $queriesFor = function (): int {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->actingAs($this->user)->get(route('app.channels.grid', $this->instagram))->assertOk();

        return $count;
    };

    gridPost($this->instagram, '2026-09-01 10:00:00');
    $queriesFor();
    $few = $queriesFor();

    foreach (range(2, 7) as $day) {
        gridPost($this->instagram, "2026-09-0{$day} 10:00:00", files: ['a.jpg', 'b.jpg']);
    }

    $many = $queriesFor();

    expect($many)->toBe($few);
});
