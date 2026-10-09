<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Models\AnalyticsPublication;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->prepare = require database_path('migrations/2026_10_08_222940_prepare_post_platforms_for_merge_into_posts.php');
    $this->backfill = require database_path('migrations/2026_10_08_222943_backfill_posts_from_post_platforms.php');
    $this->drop = require database_path('migrations/2026_10_08_222944_drop_post_platforms_table.php');

    if (! Schema::hasTable('post_platforms')) {
        Schema::create('post_platforms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('post_id');
            $table->uuid('social_account_id')->nullable();
            $table->string('platform');
            $table->string('platform_name')->nullable();
            $table->string('platform_username')->nullable();
            $table->string('platform_avatar')->nullable();
            $table->string('content_type');
            $table->string('status')->default('pending');
            $table->string('platform_post_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->text('platform_url')->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_context')->nullable();
            $table->timestamp('connection_warning_sent_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->json('meta')->nullable();
            $table->json('thread_reply_ids')->nullable();
            $table->boolean('scheduled_before_media_checks')->default(false);
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasColumn('analytics_publications', 'post_platform_id')) {
        Schema::table('analytics_publications', function (Blueprint $table): void {
            $table->uuid('post_platform_id')->nullable()->unique();
            $table->foreign('post_platform_id')->references('id')->on('post_platforms')->nullOnDelete();
        });
    }

    if (! DB::getPdo()->inTransaction()) {
        DB::getPdo()->beginTransaction();
    }

    $this->target = function (Post $post, array $attributes = []): string {
        $id = (string) Str::uuid7();

        DB::table('post_platforms')->insert([
            'id' => $id,
            'post_id' => $post->id,
            'platform' => 'linkedin',
            'content_type' => 'linkedin_post',
            'status' => 'pending',
            'enabled' => true,
            'meta' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);

        return $id;
    };
});

afterEach(function () {
    if (Schema::hasColumn('analytics_publications', 'post_platform_id')) {
        Schema::table('analytics_publications', function (Blueprint $table): void {
            $table->dropForeign(['post_platform_id']);
            $table->dropUnique(['post_platform_id']);
            $table->dropColumn('post_platform_id');
        });
    }

    Schema::dropIfExists('post_platforms');
});

test('prepare drops disabled destinations that never published', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Failed]);
    $kept = ($this->target)($post, ['social_account_id' => $account->id, 'status' => 'failed']);
    ($this->target)($post, ['social_account_id' => $account->id, 'enabled' => false, 'status' => 'rejected', 'platform_post_id' => 'remote']);

    $this->prepare->up();

    expect(DB::table('post_platforms')->where('post_id', $post->id)->pluck('id')->all())->toBe([$kept]);
});

test('prepare turns non-draft posts left without a destination into drafts and keeps channel-less drafts', function () {
    $workspace = Workspace::factory()->create();
    $scheduled = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => 'custom',
        'scheduled_at' => now()->addDay(),
        'recurrence_interval' => 1,
        'recurrence_frequency' => 'day',
    ]);
    ($this->target)($scheduled, ['enabled' => false]);
    $failed = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Failed, 'published_at' => now()]);
    $draft = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Draft, 'content' => 'Keep me']);

    $this->prepare->up();

    expect($scheduled->fresh())
        ->status->toBe(PostStatus::Draft)
        ->schedule_mode->toBeNull()
        ->recurrence_interval->toBeNull()
        ->and($failed->fresh())
        ->status->toBe(PostStatus::Draft)
        ->published_at->toBeNull()
        ->and($draft->fresh())
        ->status->toBe(PostStatus::Draft)
        ->content->toBe('Keep me');
});

test('prepare settles a partially published post from its only destination', function () {
    $account = SocialAccount::factory()->create();
    $publishedAt = now()->subHour()->startOfSecond();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    DB::table('posts')->where('id', $post->id)->update(['status' => 'partially_published', 'published_at' => now()]);
    ($this->target)($post, ['social_account_id' => $account->id, 'status' => 'published', 'published_at' => $publishedAt]);
    $failed = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    DB::table('posts')->where('id', $failed->id)->update(['status' => 'partially_published', 'published_at' => now()]);
    ($this->target)($failed, ['social_account_id' => $account->id, 'status' => 'failed']);

    $this->prepare->up();

    expect(DB::table('posts')->where('id', $post->id)->value('status'))->toBe('published')
        ->and(Post::query()->find($post->id)->published_at->toIso8601String())->toBe($publishedAt->toIso8601String())
        ->and(DB::table('posts')->where('id', $failed->id)->value('status'))->toBe('failed')
        ->and(DB::table('posts')->where('id', $failed->id)->value('published_at'))->toBeNull();
});

test('prepare refuses posts that still have several destinations', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Scheduled]);
    ($this->target)($post, ['social_account_id' => $account->id]);
    ($this->target)($post, ['social_account_id' => $account->id]);

    $this->prepare->up();
})->throws(RuntimeException::class, '1 posts with more than one destination');

test('prepare refuses a destination whose account belongs to another workspace', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['status' => PostStatus::Draft]);
    ($this->target)($post, ['social_account_id' => $account->id]);

    $this->prepare->up();
})->throws(RuntimeException::class, 'account belongs to another workspace');

test('prepare refuses a disabled destination that published', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Published]);
    ($this->target)($post, ['social_account_id' => $account->id, 'status' => 'published']);
    ($this->target)($post, ['social_account_id' => $account->id, 'status' => 'published', 'enabled' => false]);

    $this->prepare->up();
})->throws(RuntimeException::class, 'disabled destinations that published');

test('backfill copies every destination onto its post without touching updated_at', function () {
    $account = SocialAccount::factory()->create();
    $updatedAt = now()->subDays(3)->startOfSecond();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Publishing]);
    DB::table('posts')->where('id', $post->id)->update(['updated_at' => $updatedAt]);
    $targetUpdatedAt = now()->subHour()->startOfSecond();
    $retryAt = now()->addHour()->startOfSecond();
    $target = ($this->target)($post, [
        'social_account_id' => $account->id,
        'platform_name' => 'Acme',
        'platform_username' => 'acme',
        'platform_avatar' => 'avatars/acme.png',
        'content_type' => 'linkedin_post',
        'status' => 'retrying',
        'platform_post_id' => 'urn:li:share:1',
        'platform_url' => 'https://example.com/post',
        'error_message' => 'Rate limited',
        'error_context' => json_encode(['category' => 'rate_limit', 'thread_progress' => [['id' => '1', 'hash' => 'a']]]),
        'meta' => json_encode(['document_title' => 'Deck', 'link_preview' => false]),
        'thread_reply_ids' => json_encode(['2', '3']),
        'retry_at' => $retryAt,
        'scheduled_before_media_checks' => true,
        'updated_at' => $targetUpdatedAt,
    ]);

    $this->backfill->up();

    $fresh = Post::query()->find($post->id);

    expect($fresh)
        ->social_account_id->toBe($account->id)
        ->publish_status->toBe(PublishStatus::Retrying)
        ->platform_name->toBe('Acme')
        ->platform_username->toBe('acme')
        ->platform_avatar->toBe('avatars/acme.png')
        ->platform_post_id->toBe('urn:li:share:1')
        ->platform_url->toBe('https://example.com/post')
        ->error_message->toBe('Rate limited')
        ->thread_reply_ids->toBe(['2', '3'])
        ->scheduled_before_media_checks->toBeTrue()
        ->legacy_target_id->toBe($target)
        ->and($fresh->meta)->toEqual(['document_title' => 'Deck', 'link_preview' => false])
        ->and($fresh->error_context)->toEqual(['category' => 'rate_limit', 'thread_progress' => [['id' => '1', 'hash' => 'a']]])
        ->and($fresh->retry_at->toIso8601String())->toBe($retryAt->toIso8601String())
        ->and($fresh->publication_updated_at->toIso8601String())->toBe($targetUpdatedAt->toIso8601String())
        ->and($fresh->updated_at->toIso8601String())->toBe($updatedAt->toIso8601String());
});

test('backfill keeps the publish time the post already shows, only for a published destination', function () {
    $account = SocialAccount::factory()->create();
    $postTime = now()->subDay()->startOfSecond();
    $published = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Published, 'published_at' => $postTime]);
    ($this->target)($published, ['social_account_id' => $account->id, 'status' => 'published', 'published_at' => $postTime->subSecond()]);
    $imported = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Published, 'published_at' => null]);
    ($this->target)($imported, ['social_account_id' => $account->id, 'status' => 'published', 'published_at' => $postTime]);
    $failed = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Failed, 'published_at' => $postTime]);
    ($this->target)($failed, ['social_account_id' => $account->id, 'status' => 'failed']);

    $this->backfill->up();

    expect(Post::query()->find($published->id)->published_at->toIso8601String())->toBe($postTime->toIso8601String())
        ->and(Post::query()->find($imported->id)->published_at->toIso8601String())->toBe($postTime->toIso8601String())
        ->and(Post::query()->find($failed->id)->published_at)->toBeNull();
});

test('backfill links analytics publications by post and replaces the partially published webhook event', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Published]);
    $target = ($this->target)($post, ['social_account_id' => $account->id, 'status' => 'published', 'platform_post_id' => 'remote-1']);
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $account->workspace_id, 'social_account_id' => $account->id]);
    DB::table('analytics_publications')->where('id', $publication->id)->update(['post_platform_id' => $target]);
    $only = Webhook::factory()->create(['workspace_id' => $account->workspace_id, 'events' => ['post.partially_published']]);
    $mixed = Webhook::factory()->create(['workspace_id' => $account->workspace_id, 'events' => ['post.published', 'post.partially_published']]);

    $this->backfill->up();
    $this->backfill->up();

    expect($publication->fresh()->post_id)->toBe($post->id)
        ->and($only->fresh()->events)->toEqualCanonicalizing(['post.published', 'post.failed'])
        ->and($mixed->fresh()->events)->toEqualCanonicalizing(['post.published', 'post.failed']);
});

test('backfill clears the copy of a destination that vanished before it runs again', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Draft]);
    $target = ($this->target)($post, ['social_account_id' => $account->id]);

    $this->backfill->up();
    DB::table('post_platforms')->where('id', $target)->delete();
    $this->backfill->up();

    expect(Post::query()->find($post->id))
        ->legacy_target_id->toBeNull()
        ->social_account_id->toBeNull()
        ->platform->toBeNull();
});

test('backfill parity fails when a copied column differs', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    ($this->target)($post, ['social_account_id' => $account->id, 'meta' => json_encode(['title' => 'A'])]);

    $this->backfill->up();
    DB::table('posts')->where('id', $post->id)->update(['meta' => json_encode(['title' => 'B'])]);

    (fn () => $this->assertParity())->call($this->backfill);
})->throws(RuntimeException::class, 'Backfill parity failed');

test('drop refuses destinations that were not copied, then drops the table', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    ($this->target)($post, ['social_account_id' => $account->id]);

    expect(fn () => $this->drop->up())->toThrow(RuntimeException::class, 'were not copied');

    $this->backfill->up();
    $this->drop->up();

    expect(Schema::hasTable('post_platforms'))->toBeFalse()
        ->and(Schema::hasColumn('analytics_publications', 'post_platform_id'))->toBeFalse();
});

test('prepare changes nothing when its guard stops', function () {
    $account = SocialAccount::factory()->create();
    $blocked = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Scheduled]);
    ($this->target)($blocked, ['social_account_id' => $account->id]);
    ($this->target)($blocked, ['social_account_id' => $account->id]);
    $orphan = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Failed]);
    $disabled = ($this->target)($blocked, ['social_account_id' => $account->id, 'enabled' => false]);

    expect(fn () => $this->prepare->up())->toThrow(RuntimeException::class);

    expect($orphan->fresh()->status)->toBe(PostStatus::Failed)
        ->and(DB::table('post_platforms')->where('id', $disabled)->exists())->toBeTrue();
});

test('prepare deletes published posts left without a destination, with their media files after commit', function () {
    Storage::fake();
    $account = SocialAccount::factory()->create();
    $orphan = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Published, 'published_at' => now()]);
    $media = Media::factory()->create(['workspace_id' => $account->workspace_id, 'post_id' => $orphan->id, 'mediable_type' => null, 'mediable_id' => null, 'collection' => Media::COLLECTION_MEDIA]);
    Storage::put($media->path, 'image');
    $kept = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Published, 'published_at' => now()]);
    ($this->target)($kept, ['social_account_id' => $account->id, 'status' => 'published']);
    $shared = Media::factory()->create(['workspace_id' => $account->workspace_id, 'post_id' => $orphan->id, 'mediable_type' => null, 'mediable_id' => null, 'collection' => Media::COLLECTION_MEDIA]);
    Media::factory()->create(['workspace_id' => $account->workspace_id, 'post_id' => $kept->id, 'path' => $shared->path, 'mediable_type' => null, 'mediable_id' => null, 'collection' => Media::COLLECTION_MEDIA]);
    Storage::put($shared->path, 'image');
    $disabledOnly = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Published, 'published_at' => now()]);
    ($this->target)($disabledOnly, ['social_account_id' => $account->id, 'enabled' => false]);

    $this->prepare->up();

    expect(Post::query()->whereKey([$orphan->id, $disabledOnly->id])->exists())->toBeFalse()
        ->and(Media::query()->whereKey([$media->id, $shared->id])->exists())->toBeFalse()
        ->and($kept->fresh())->not->toBeNull();
    Storage::assertMissing($media->path);
    Storage::assertExists($shared->path);
});

test('prepare drops destinations whose account is gone, as disconnecting a channel does', function () {
    $workspace = Workspace::factory()->create();
    $published = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Published, 'published_at' => now()]);
    ($this->target)($published, ['social_account_id' => null, 'status' => 'published']);
    $scheduled = Post::factory()->create(['workspace_id' => $workspace->id, 'status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
    ($this->target)($scheduled, ['social_account_id' => null]);

    $this->prepare->up();

    expect(Post::query()->whereKey($published->id)->exists())->toBeFalse()
        ->and($scheduled->fresh()->status)->toBe(PostStatus::Draft)
        ->and(DB::table('post_platforms')->where('post_id', $scheduled->id)->exists())->toBeFalse();
});

test('backfill refuses a destination that is switched off', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    ($this->target)($post, ['social_account_id' => $account->id, 'enabled' => false]);

    $this->backfill->up();
})->throws(RuntimeException::class, 'switched off or lost their channel');

test('drop refuses analytics publications that were not linked to their post', function () {
    $account = SocialAccount::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Published]);
    $target = ($this->target)($post, ['social_account_id' => $account->id, 'status' => 'published']);
    $this->backfill->up();
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $account->workspace_id, 'social_account_id' => $account->id]);
    DB::table('analytics_publications')->where('id', $publication->id)->update(['post_platform_id' => $target, 'post_id' => null]);

    expect(fn () => $this->drop->up())->toThrow(RuntimeException::class, 'were not linked to their posts')
        ->and(Schema::hasTable('post_platforms'))->toBeTrue();
});
