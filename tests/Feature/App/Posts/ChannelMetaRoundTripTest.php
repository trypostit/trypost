<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake();
    Http::fake();

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->owner->account);
});

/**
 * Whether two meta arrays hold the same values, whatever the key order.
 *
 * @param  array<string, mixed>  $expected
 */
function channelMetaRoundTripMatches(mixed $actual, array $expected): bool
{
    return json_decode((string) json_encode($actual), true) == $expected;
}

/**
 * Creates a draft through the composer, edits it with the same meta and new
 * text, and checks the meta after each step and on the edit page.
 *
 * @param  array<string, mixed>  $meta
 * @param  array<string, mixed>  $systemMeta  meta only the system writes, set on the stored post before the edit
 */
function channelMetaRoundTrip(SocialAccount $account, ContentType $contentType, array $meta, array $systemMeta = []): Post
{
    test()->actingAs(test()->owner)
        ->post(route('app.posts.store'), [
            'status' => 'draft',
            'content' => 'First caption',
            'media' => [],
            'destinations' => [['social_account_id' => $account->id, 'content_type' => $contentType->value, 'meta' => $meta]],
        ])
        ->assertSessionHasNoErrors();

    $post = Post::query()->where('social_account_id', $account->id)->sole();
    $stored = $post->meta;

    foreach (Arr::except($meta, 'thread_replies') as $key => $value) {
        expect(data_get($stored, $key))->toEqual($value);
    }

    if ($systemMeta !== []) {
        $post->forceFill(['meta' => [...$stored, ...$systemMeta]])->saveQuietly();
    }

    test()->actingAs(test()->owner)
        ->put(route('app.posts.update', $post), [
            'status' => 'draft',
            'content' => 'Edited caption',
            'content_type' => $contentType->value,
            'meta' => $stored,
            'media' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $edited = $post->fresh();
    $expected = [...$stored, ...$systemMeta];

    expect($edited->content)->toContain('Edited caption')
        ->and($edited->meta)->toEqual($expected);

    test()->actingAs(test()->owner)
        ->get(route('app.posts.edit', $edited))
        ->assertRedirect(route('app.posts.index', ['edit' => $edited->id]));

    test()->actingAs(test()->owner)
        ->get(route('app.posts.index', ['edit' => $edited->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('editPost.id', $edited->id)
            ->where('editPost.meta', fn (mixed $editMeta): bool => channelMetaRoundTripMatches($editMeta, $expected)));

    return $edited;
}

test('a post keeps every setting of its network through create, edit and reopen', function (Closure $account, ContentType $contentType, array $meta) {
    $post = channelMetaRoundTrip($account($this->workspace), $contentType, $meta);

    foreach ($meta as $key => $value) {
        expect(data_get($post->meta, $key))->toEqual($value);
    }
})->with([
    'linkedin page post' => [fn (Workspace $workspace) => SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $workspace->id]), ContentType::LinkedInPagePost, ['document_title' => 'Quarterly report']],
    'linkedin pdf title' => [fn (Workspace $workspace) => SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]), ContentType::LinkedInPost, ['document_title' => 'Launch deck 2026']],
    'facebook post without link preview' => [fn (Workspace $workspace) => SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]), ContentType::FacebookPost, ['link_preview' => false]],
    'facebook story' => [fn (Workspace $workspace) => SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]), ContentType::FacebookStory, ['is_ai_generated' => true]],
    'tiktok privacy and interactions' => [fn (Workspace $workspace) => SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id]), ContentType::TikTokVideo, [
        'privacy_level' => 'SELF_ONLY',
        'allow_comments' => false,
        'allow_duet' => true,
        'allow_stitch' => false,
        'auto_add_music' => true,
        'is_aigc' => true,
        'disclose' => true,
        'brand_content_toggle' => false,
        'brand_organic_toggle' => true,
    ]],
    'threads topic tag' => [fn (Workspace $workspace) => SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]), ContentType::ThreadsPost, ['topic_tag' => 'Launches']],
    'mastodon content warning and thread' => [fn (Workspace $workspace) => SocialAccount::factory()->mastodon()->create(['workspace_id' => $workspace->id]), ContentType::MastodonPost, [
        'spoiler_text' => 'Spoilers ahead',
        'thread_replies' => [['text' => 'Second toot', 'media' => []]],
    ]],
    'bluesky thread' => [fn (Workspace $workspace) => SocialAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]), ContentType::BlueskyPost, [
        'thread_replies' => [['text' => 'Second skeet', 'media' => []], ['text' => 'Third skeet', 'media' => []]],
    ]],
    'pinterest board, title and link' => [fn (Workspace $workspace) => SocialAccount::factory()->pinterest()->create(['workspace_id' => $workspace->id]), ContentType::PinterestPin, [
        'board_id' => 'board_1',
        'title' => 'Summer recipes',
        'link' => 'https://example.com/recipes',
    ]],
    'youtube title, category and privacy' => [fn (Workspace $workspace) => SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]), ContentType::YouTubeShort, [
        'title' => 'Behind the scenes',
        'description' => 'How we film',
        'category_id' => '22',
        'privacy_status' => 'unlisted',
        'notify_subscribers' => false,
        'made_for_kids' => false,
    ]],
    'google business event' => [fn (Workspace $workspace) => SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $workspace->id]), ContentType::GoogleBusinessPost, [
        'topic_type' => 'EVENT',
        'event' => [
            'title' => 'Open house',
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'start_time' => '09:00',
            'end_time' => '18:00',
        ],
        'call_to_action' => ['action_type' => 'LEARN_MORE', 'url' => 'https://example.com/open-house'],
    ]],
]);

test('editing a telegram post keeps the reactions it already has', function () {
    $telegram = SocialAccount::factory()->telegram()->create(['workspace_id' => $this->workspace->id]);
    $reactions = ['enabled' => true, 'emojis' => ['👍', '🔥']];

    $post = channelMetaRoundTrip($telegram, ContentType::TelegramPost, [], ['reactions' => $reactions]);

    expect(data_get($post->meta, 'reactions'))->toEqual($reactions)
        ->and($post->platform)->toBe(Platform::Telegram);
});

test('an x thread keeps the media of its reply through create, edit and reopen', function () {
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $image = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    $post = channelMetaRoundTrip($x, ContentType::XPost, [
        'thread_replies' => [
            ['text' => 'Second post', 'media' => [MediaItem::fromMedia($image)->toArray()]],
            ['text' => 'Third post', 'media' => []],
        ],
    ]);

    $replies = data_get($post->meta, 'thread_replies');

    expect(collect($replies[0]['media'])->pluck('id')->all())->toBe([$image->id])
        ->and($replies[0]['text'])->toBe('Second post')
        ->and($replies[1])->toEqual(['text' => 'Third post', 'media' => []])
        ->and($image->fresh()->post_id)->toBe($post->id);
});
