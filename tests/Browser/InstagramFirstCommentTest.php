<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ContentSanitizer;
use App\Support\ChannelPermissions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function instagramFirstCommentDraft(Platform $platform, ContentType $type): Post
{
    Http::fake(['*' => Http::response([])]);
    Storage::fake();
    config(["trypost.platforms.{$platform->value}.scopes" => ChannelPermissions::missingFrom([], $platform, GatedFeature::ThreadReplies)]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    connectAiTo($user);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'scopes' => match ($platform) {
            Platform::Instagram => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_comments'],
            Platform::InstagramFacebook => ['instagram_basic', 'pages_read_engagement', 'instagram_content_publish', 'instagram_manage_comments'],
        },
    ]);
    $post = Post::factory()->forAccount($account, $type)->create(['user_id' => $user->id, 'content' => 'Our launch is live']);
    $media = Media::factory()->ownedByPost($post)->stored();
    if ($type === ContentType::InstagramReel) {
        $media = $media->video();
    }
    $media = $media->create(['meta' => ['width' => 1080, 'height' => 1080, 'duration' => 20]]);
    $post->update(['media' => [MediaItem::fromMedia($media)->toArray()]]);

    test()->actingAs($user);

    return $post;
}

test('the instagram composer saves one text comment and shows it inside the post preview', function (Platform $platform, ContentType $type) {
    $post = instagramFirstCommentDraft($platform, $type);
    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->assertSeeIn('@thread-start', __('posts.form.thread.first_comment'))->click('@thread-start');
    waitFor($page, '@thread-reply-0');
    $page->fill('@thread-reply-0', 'More details in the bio');
    waitForText($page, '@instagram-first-comment-preview', 'More details in the bio');

    $page->assertMissing('@thread-add-reply')
        ->assertMissing('@preview-thread-reply-0')
        ->assertMissing("@composer-{$post->social_account_id}-reply-0-dropzone")
        ->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');
    expect(data_get($post->fresh()->meta, 'thread_replies'))->toBe([['text' => 'More details in the bio', 'media' => []]]);
    $page->assertNoJavaScriptErrors();

    $reopened = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForText($reopened, '@instagram-first-comment-preview', 'More details in the bio');
    $reopened->assertNoJavaScriptErrors();
})->with([
    'instagram login feed' => [Platform::Instagram, ContentType::InstagramFeed],
    'facebook login reel' => [Platform::InstagramFacebook, ContentType::InstagramReel],
]);

test('switching a scheduled instagram post to story blocks saving until its comment is removed', function (bool $hasCommentGrant) {
    $post = instagramFirstCommentDraft(Platform::Instagram, ContentType::InstagramFeed);
    if (! $hasCommentGrant) {
        $post->socialAccount->update(['scopes' => ['instagram_business_basic', 'instagram_business_content_publish']]);
    }
    $scheduledAt = now()->addDay()->startOfHour();
    $post->update([
        'status' => Status::Scheduled,
        'scheduled_at' => $scheduledAt,
        'meta' => ['thread_replies' => [['text' => 'A saved comment', 'media' => []]]],
    ]);
    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    $id = $post->social_account_id;
    waitFor($page, "@composer-type-{$id}-instagram_story");
    $page->click("@composer-type-{$id}-instagram_story");

    waitForText($page, "@thread-unavailable-warning-{$id}", __('posts.form.thread.instagram_story'));
    $page->assertMissing("@channel-permission-warning-{$id}");
    waitForState($page, '@composer-submit', "element.getAttribute('aria-disabled') === 'true'");
    $page->assertMissing('@instagram-first-comment-preview')->click("@composer-type-{$id}-instagram_feed");
    waitForText($page, '@instagram-first-comment-preview', 'A saved comment');
    $page->assertMissing("@thread-unavailable-warning-{$id}")->click("@composer-type-{$id}-instagram_story");
    waitForText($page, "@thread-unavailable-warning-{$id}", __('posts.form.thread.instagram_story'));
    $page->click('@thread-reply-remove-0');
    waitForGone($page, "@thread-unavailable-warning-{$id}");
    waitForState($page, '@composer-submit', "element.getAttribute('aria-disabled') !== 'true'");
    $page->click('@composer-submit');
    waitForGone($page, '@post-composer-dialog');

    $saved = $post->fresh();
    expect($saved->status)->toBe(Status::Scheduled)
        ->and($saved->scheduled_at->equalTo($scheduledAt))->toBeTrue()
        ->and($saved->content_type)->toBe(ContentType::InstagramStory)
        ->and(data_get($saved->meta, 'thread_replies'))->toBeNull();
    $page->assertNoJavaScriptErrors();
})->with(['with comment grant' => true, 'without comment grant' => false]);

test('instagram previews the exact text sent to instagram', function (string $text) {
    $post = instagramFirstCommentDraft(Platform::Instagram, ContentType::InstagramFeed);
    $post->update(['meta' => ['thread_replies' => [$text]]]);
    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);

    $displayText = app(ContentSanitizer::class)->sanitize($text, Platform::Instagram);
    waitForText($page, '@instagram-first-comment-preview', $displayText);
    $page->assertDontSeeIn('@instagram-first-comment-preview', '<b>')->assertNoJavaScriptErrors();
})->with([
    'legacy markup' => '<b>Launch</b> &amp; links',
    'legacy paragraphs and lists' => '<p>Launch</p><ul><li>First<br>Second</li><li>Third</li></ul>',
    'legacy break with attributes' => 'First<br class="legacy">Second',
    'query string' => 'https://example.com/?plan=pro&copy=1',
    'literal angle bracket' => 'Price: <limit',
    'entities' => 'A &copy; &#x1F389; &#65; &#128; &copycat;',
]);

test('the instagram comment counter counts non-breaking spaces like the publisher', function () {
    $post = instagramFirstCommentDraft(Platform::Instagram, ContentType::InstagramFeed);
    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->click('@thread-start')->fill('@thread-reply-0', '&nbsp;'.Str::repeat('a', 2200));

    waitForState($page, '@composer-submit', "element.getAttribute('aria-disabled') === 'true'");
    $page->assertNoJavaScriptErrors();
});

test('the instagram comment field measures the text instagram receives, as the composer does', function () {
    $post = instagramFirstCommentDraft(Platform::Instagram, ContentType::InstagramFeed);
    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->click('@thread-start')->fill('@thread-reply-0', Str::repeat('&amp;', 450));

    letPageRun($page, 500);
    $page->assertAttributeMissing('@thread-reply-0', 'aria-invalid');

    $page->fill('@thread-reply-0', Str::repeat('a', 2201));
    waitForState($page, '@thread-reply-0', "element.getAttribute('aria-invalid') === 'true'");
    $page->assertNoJavaScriptErrors();
});

test('the instagram first comment reconnect action is shown only to workspace users who can use it', function (bool $canReconnect) {
    $post = instagramFirstCommentDraft(Platform::Instagram, ContentType::InstagramFeed);
    $post->socialAccount->update(['scopes' => ['instagram_business_basic', 'instagram_business_content_publish']]);
    $post->update(['meta' => ['thread_replies' => ['A saved comment']]]);
    if (! $canReconnect) {
        $member = workspaceMember($post->workspace);
        $post->update(['user_id' => $member->id]);
        connectAiTo($member);
        test()->actingAs($member);
    }

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    $warning = "@channel-permission-warning-{$post->social_account_id}";
    $message = $canReconnect ? 'message' : 'member_message';
    waitForText($page, $warning, __("channels.permissions.thread_replies.{$message}", ['network' => 'Instagram']));
    waitForState($page, '@composer-submit', "element.getAttribute('aria-disabled') === 'true'");

    if ($canReconnect) {
        $page->assertVisible("{$warning}-reconnect");
    } else {
        $page->assertMissing("{$warning}-reconnect");
    }

    $page->assertNoJavaScriptErrors();
})->with(['workspace owner' => true, 'workspace member' => false]);

test('the composer applies first comment support separately for the two instagram login variants', function (bool $facebookEnabled) {
    $draft = instagramFirstCommentDraft(Platform::Instagram, ContentType::InstagramFeed);
    $instagram = $draft->socialAccount;
    $facebookScopes = data_get(trypostConfigWith(['INSTAGRAM_FACEBOOK_SCOPES' => '']), 'platforms.instagram-facebook.scopes');
    if ($facebookEnabled) {
        $facebookScopes[] = 'instagram_manage_comments';
    }
    config(['trypost.platforms.instagram-facebook.scopes' => $facebookScopes]);
    $facebook = SocialAccount::factory()->create([
        'workspace_id' => $draft->workspace_id,
        'platform' => Platform::InstagramFacebook,
        'scopes' => ['instagram_basic', 'pages_read_engagement', 'instagram_content_publish', 'instagram_manage_comments'],
    ]);

    $page = visit(route('app.posts.create'))->resize(1280, 900);
    waitForOpen($page, '@post-composer-dialog');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$instagram->id}")
        ->click("@composer-account-option-{$facebook->id}")
        ->fill('@composer-base-content', 'A shared caption')
        ->click('@composer-next');
    waitFor($page, "@composer-caption-{$instagram->id}");
    $page->click('@thread-start')->fill('@thread-reply-0', 'Direct login comment');
    waitForText($page, '@instagram-first-comment-preview', 'Direct login comment');

    $page->click("@composer-expand-{$facebook->id}");
    waitFor($page, "@composer-caption-{$facebook->id}");
    $page->assertMissing('@instagram-first-comment-preview');
    $facebookComment = null;
    if ($facebookEnabled) {
        $facebookComment = 'Facebook login comment';
        $page->click('@thread-start')->fill('@thread-reply-0', $facebookComment);
        waitForText($page, '@instagram-first-comment-preview', $facebookComment);
    } else {
        $page->assertMissing('@thread-start')->assertMissing("@channel-permission-warning-{$facebook->id}");
    }
    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    $comments = $draft->workspace->posts()->whereKeyNot($draft->id)->get()->mapWithKeys(fn (Post $post): array => [
        $post->social_account_id => data_get($post->meta, 'thread_replies.0.text'),
    ]);
    expect($comments->all())->toEqual([
        $instagram->id => 'Direct login comment',
        $facebook->id => $facebookComment,
    ]);
    $page->assertNoJavaScriptErrors();
})->with(['enabled explicitly' => true, 'disabled by default' => false]);
