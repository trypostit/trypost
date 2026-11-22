<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\MissingPermissionException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\XPublisher;
use App\Support\ChannelPermissions;
use App\Support\PostPlatformMetaRules;
use App\Support\ThreadReplies;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

function xAccountGranting(?array $scopes): SocialAccount
{
    return SocialAccount::factory()->make(['platform' => Platform::X, 'scopes' => $scopes]);
}

beforeEach(function () {
    gateFeature(Platform::X, GatedFeature::ThreadReplies, ['tweet.moderate.write'], ['message' => 'posts.form.thread.reply_empty', 'member_message' => 'posts.form.thread.unsupported']);
});

test('a channel without the scope a feature needs is held back from it and told so', function () {
    $thread = ['thread_replies' => ['second']];
    $without = xAccountGranting(['tweet.write', 'tweet.read', 'users.read']);
    $with = xAccountGranting(['tweet.write', 'tweet.read', 'users.read', 'tweet.moderate.write']);

    expect(ChannelPermissions::violation($without, $thread, null))
        ->toBe(['meta.thread_replies', __('posts.form.thread.reply_empty')])
        ->and(ChannelPermissions::missing($without))
        ->toBe(['thread_replies' => ['message' => 'posts.form.thread.reply_empty', 'member_message' => 'posts.form.thread.unsupported']])
        ->and(ChannelPermissions::violation($with, $thread, null))->toBeNull()
        ->and(ChannelPermissions::missing($with))->toBe([]);
});

test('a post that does not use the feature is never held back by its scope', function () {
    $without = xAccountGranting(['tweet.write', 'tweet.read', 'users.read']);

    expect(ChannelPermissions::violation($without, ['thread_replies' => []], null))->toBeNull()
        ->and(ChannelPermissions::violation($without, null, null))->toBeNull();
});

test('a channel lacking the publish scope is refused on the channel, with the network named', function () {
    $account = xAccountGranting(['tweet.read', 'users.read']);

    expect(ChannelPermissions::violation($account, [], null))
        ->toBe(['social_account_id', __('channels.permissions.publish.message', ['network' => Platform::X->label()])]);
});

test('without its own copy a thread refusal names the network, and publishing comes before replies', function () {
    gateFeature(Platform::X, GatedFeature::ThreadReplies, ['tweet.moderate.write']);
    $thread = ['thread_replies' => ['second']];

    expect(ChannelPermissions::violation(xAccountGranting(['tweet.write']), $thread, null))
        ->toBe(['meta.thread_replies', __('channels.permissions.thread_replies.message', ['network' => Platform::X->label()])])
        ->and(ChannelPermissions::violation(xAccountGranting(['tweet.read']), $thread, null)[0])->toBe('social_account_id');
});

test('a member who cannot reconnect the channel is told who can', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::X, 'scopes' => ['tweet.read', 'users.read']]);
    $member = workspaceMember($workspace);

    expect(ChannelPermissions::violation($account, [], $member))
        ->toBe(['social_account_id', __('channels.permissions.publish.member_message', ['network' => Platform::X->label()])]);
});

test('a requirement of several scopes is lacking when any one is, and an alternative satisfies its requirement', function () {
    $youtube = SocialAccount::factory()->make(['platform' => Platform::YouTube, 'scopes' => ['https://www.googleapis.com/auth/youtube.force-ssl']]);

    expect(ChannelPermissions::missingScope(xAccountGranting(['tweet.read']), GatedFeature::Followers))->toBe('users.read')
        ->and(ChannelPermissions::lacks($youtube, GatedFeature::Followers))->toBeFalse();
});

test('every analytics feature a channel lacks is listed with the insights copy', function () {
    $account = xAccountGranting(['tweet.write']);

    expect(array_keys(ChannelPermissions::missing($account)))->toBe(['thread_replies', 'followers', 'post_insights', 'post_history'])
        ->and(ChannelPermissions::missing($account)['followers'])
        ->toBe(['message' => 'channels.permissions.insights.message', 'member_message' => 'channels.permissions.insights.member_message']);
});

test('unknown scopes, an unknown channel and a network that gates nothing hold nothing back', function () {
    $thread = ['thread_replies' => ['second']];
    $telegram = SocialAccount::factory()->make(['platform' => Platform::Telegram, 'scopes' => ['anything']]);

    expect(ChannelPermissions::violation(xAccountGranting(null), $thread, null))->toBeNull()
        ->and(ChannelPermissions::violation(null, $thread, null))->toBeNull()
        ->and(ChannelPermissions::scopes(Platform::Telegram, GatedFeature::Publish))->toBe([])
        ->and(ChannelPermissions::missing($telegram))->toBe([]);
});

test('a login reporting no scopes lacks every publish scope, while a stored grant that is unknown lacks none', function () {
    expect(ChannelPermissions::missingFrom([], Platform::X, GatedFeature::Publish))->toBe(['tweet.write'])
        ->and(ChannelPermissions::missingFrom(['tweet.write'], Platform::X, GatedFeature::Publish))->toBe([])
        ->and(ChannelPermissions::missingFrom(['https://www.googleapis.com/auth/youtube'], Platform::YouTube, GatedFeature::Followers))->toBe([])
        ->and(ChannelPermissions::missingFrom([], Platform::YouTube, GatedFeature::Followers))->toBe(['https://www.googleapis.com/auth/youtube.readonly'])
        ->and(ChannelPermissions::missingScope(xAccountGranting(null), GatedFeature::Publish))->toBeNull();
});

test('a channel is never told to reconnect for an optional read or a scope TryPost no longer asks for', function () {
    config(['trypost.platforms.tiktok.scopes' => data_get(trypostConfigWith(['TIKTOK_SCOPES' => '']), 'platforms.tiktok.scopes')]);
    $facebook = SocialAccount::factory()->make(['platform' => Platform::Facebook, 'scopes' => ['pages_manage_posts', 'pages_read_engagement']]);
    $tiktok = SocialAccount::factory()->make(['platform' => Platform::TikTok, 'scopes' => ['user.info.basic', 'video.publish']]);

    expect(ChannelPermissions::lacks($facebook, GatedFeature::PostInsightsExtended))->toBeTrue()
        ->and(ChannelPermissions::missing($facebook))->toBe([])
        ->and(array_keys(ChannelPermissions::missing($tiktok)))->toBe(['followers', 'post_insights', 'post_history']);

    config(['trypost.platforms.tiktok.scopes' => ['user.info.basic', 'video.publish', 'video.upload']]);

    expect(ChannelPermissions::missing($tiktok))->toBe([]);
});

test('a channel missing only analytics scopes is never held back from a post', function () {
    expect(ChannelPermissions::violation(xAccountGranting(['tweet.write']), [], null))->toBeNull();
});

test('a publisher refuses a feature its channel lacks as a permission, before anything goes out', function () {
    $account = SocialAccount::factory()->x()->create(['scopes' => ['tweet.write', 'tweet.read', 'users.read']]);
    $post = Post::factory()->forAccount($account, ContentType::XPost)->create([
        'content' => 'First post',
        'meta' => ['thread_replies' => [['text' => 'Second post', 'media' => []]]],
    ]);
    Http::fake();

    expect(fn () => app(XPublisher::class)->publish($post))
        ->toThrow(fn (MissingPermissionException $exception) => expect($exception->category)->toBe(ErrorCategory::Permission)
            ->and($exception->platformErrorCode)->toBe('missing_permission')
            ->and($exception->userMessage)->toBe(__('posts.form.thread.reply_empty')));
    Http::assertNothingSent();
});

test('every locale names the network wherever english does', function () {
    $english = trans('channels.permissions', locale: 'en');

    foreach (glob(lang_path('*'), GLOB_ONLYDIR) as $directory) {
        $locale = basename($directory);

        foreach (Arr::dot($english) as $key => $copy) {
            if (str_contains((string) $copy, ':network')) {
                expect((string) trans("channels.permissions.{$key}", locale: $locale))->toContain(':network');
            }
        }
    }
});

test('a post type that stands alone lists no thread replies', function () {
    config(['trypost.platforms.x.replies.standalone_types' => [ContentType::XPoll->value => 'posts.form.thread.reply_empty']]);

    expect(ContentType::XPoll->takesThreadReplies())->toBeFalse()
        ->and(ContentType::XPost->takesThreadReplies())->toBeTrue();
});

test('publishing is always asked for, even when the env list leaves its scope out', function () {
    config(['trypost.platforms.tiktok.scopes' => ['user.info.basic', 'video.upload']]);
    $account = SocialAccount::factory()->make(['platform' => Platform::TikTok, 'scopes' => ['user.info.basic', 'video.upload']]);

    expect(ChannelPermissions::requestedScopes(Platform::TikTok))->toBe(['user.info.basic', 'video.upload', 'video.publish'])
        ->and(ChannelPermissions::disabledFeatures(Platform::TikTok))->not->toContain(GatedFeature::Publish->value)
        ->and(ChannelPermissions::violation($account, [], null))->toBe(['social_account_id', __('channels.permissions.publish.message', ['network' => Platform::TikTok->label()])]);
});

test('a publish requirement met by any of its alternatives adds nothing to the request', function () {
    config(['trypost.platforms.mastodon.scopes' => ['read', 'write']]);

    expect(ChannelPermissions::requestedScopes(Platform::Mastodon))->toBe(['read', 'write']);
});

test('a feature whose scopes the connect does not ask for is off: no warning and nothing to reconnect for', function () {
    config(['trypost.platforms.threads.scopes' => ['threads_basic', 'threads_content_publish']]);
    $account = SocialAccount::factory()->make(['platform' => Platform::Threads, 'scopes' => ['threads_basic', 'threads_content_publish']]);

    expect(ChannelPermissions::disabledFeatures(Platform::Threads))->toBe(['followers', 'post_insights'])
        ->and(ChannelPermissions::permits($account, GatedFeature::PostInsights))->toBeFalse()
        ->and(ChannelPermissions::permits($account, GatedFeature::PostHistory))->toBeTrue()
        ->and(ChannelPermissions::missing($account))->toBe([]);
});

test('replies are off on a network whose connect does not ask for their scope', function () {
    config(['trypost.platforms.x.scopes' => ['tweet.read', 'tweet.write', 'users.read']]);

    expect(ThreadReplies::supports(Platform::X))->toBeFalse()
        ->and(ContentType::XPost->takesThreadReplies())->toBeFalse()
        ->and(PostPlatformMetaRules::requiredMetaViolation(Platform::X, ['thread_replies' => ['second']], ContentType::XPost))
        ->toBe(['thread_replies', __('posts.form.thread.unsupported')]);

    gateFeature(Platform::X, GatedFeature::ThreadReplies, ['tweet.moderate.write']);

    expect(ThreadReplies::supports(Platform::X))->toBeTrue();
});
