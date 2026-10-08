<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Mail\PostApprovalRequested;
use App\Mail\PostApproved;
use App\Mail\PostNoteAdded;
use App\Mail\PostRejected;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Mail\WorkspaceInvite;
use App\Models\Account;
use App\Models\Invite;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Mail\ApprovalEmailPosts;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\DomCrawler\Crawler;

test('the shared email layout keeps the action and the appropriate footer', function (string $template, bool $hasPreferences, Locale $locale) {
    app()->setLocale($locale->value);

    $html = view("mail.{$template}", [
        'title' => 'TryPost',
        'previewText' => 'Your TryPost update',
        'url' => 'https://example.test/email-action',
        'user' => User::factory()->make(['name' => 'Paulo']),
        'workspaceName' => 'Launch Team',
        'platformName' => 'YouTube',
        'accountName' => 'Paulo',
        'account' => SocialAccount::factory()->make(['platform' => Platform::YouTube, 'display_name' => 'Paulo']),
        'isAdmin' => false,
        'requiresApproval' => true,
    ])->render();

    $document = new Crawler($html);

    expect($document->filter('h1')->count())->toBe(1)
        ->and($document->filter('a[href="https://example.test/email-action"]')->count())->toBe(1)
        ->and($document->filter('a[href="https://www.instagram.com/trypost.it"]')->count())->toBe(1)
        ->and($document->filter('a[href="'.route('app.notifications.preferences').'"]')->count())->toBe($hasPreferences ? 1 : 0)
        ->and($document->filter('html')->attr('dir'))->toBe($locale->direction())
        ->and($document->filter('h1')->ancestors()->first()->attr('align'))->toBe($locale->direction() === 'rtl' ? 'right' : 'left')
        ->and($html)->not->toContain('<x-', 'trypost.en');
})->with([
    'notification' => ['account-disconnected', true],
    'invitation' => ['workspace-invite', false],
    'verification' => ['email-verification', false],
    'password reset' => ['password-reset', false],
])->with([Locale::English, Locale::PortugueseBrazil, Locale::Arabic]);

test('the workspace invite renders in the requested locale and names the workspace', function () {
    $account = Account::factory()->create(['name' => 'Acme Co']);
    $workspace = Workspace::factory()->create(['account_id' => $account->id, 'name' => 'Launch Team']);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$workspace->id],
        ...membershipPivot('approval'),
    ]);

    $mailable = (new WorkspaceInvite($invite))->locale(Locale::PortugueseBrazil->value);

    $mailable->assertHasSubject(__('mail.workspace_invite.subject', ['workspace' => 'Launch Team'], 'pt-BR'));
    $mailable->assertSeeInHtml(__('mail.workspace_invite.heading', [], 'pt-BR'));
    $mailable->assertSeeInHtml(__('mail.workspace_invite.expiry', [], 'pt-BR'));
    $mailable->assertSeeInHtml(__('mail.workspace_invite.intro', ['workspace' => 'Launch Team'], 'pt-BR'), false);
    $mailable->assertDontSeeInHtml('Acme Co');
    $mailable->assertSeeInHtml(__('mail.workspace_invite.roles.needs_approval', [], 'pt-BR'));
});

test('the workspace invite names the invited access', function (string $access, string $label) {
    $invite = Invite::factory()->create(membershipPivot($access));

    $mailable = new WorkspaceInvite($invite);
    $strong = fn (string $key): string => '<strong>'.e(__("mail.workspace_invite.roles.{$key}")).'</strong>';

    $mailable->assertSeeInHtml($strong($label), false);

    collect(['admin', 'member', 'needs_approval'])
        ->reject(fn (string $key): bool => $key === $label)
        ->each(fn (string $key) => $mailable->assertDontSeeInHtml($strong($key), false));
})->with([
    'admin' => ['admin', 'admin'],
    'member' => ['member', 'member'],
    'needs approval' => ['approval', 'needs_approval'],
]);

test('the post note email renders the note, the post and its channels', function (Locale $locale) {
    $author = User::factory()->create(['name' => 'Ana Author']);
    $workspace = Workspace::factory()->create([
        'account_id' => $author->account_id,
        'user_id' => $author->id,
        'name' => 'Acme Workspace',
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $author->id,
        'content' => '<p>Launch day is <strong>here</strong></p>',
    ]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'display_name' => 'Acme Inc',
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::LinkedIn,
    ]);
    $note = PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $author->id,
        'body' => 'Can we swap the image?',
    ]);

    $mailable = (new PostNoteAdded($note, $author))->locale($locale->value);

    $mailable->assertHasSubject(__('mail.post_note_added.subject', ['author' => 'Ana Author'], $locale->value));
    $mailable->assertSeeInHtml(__('mail.post_note_added.heading', [], $locale->value));
    $mailable->assertSeeInHtml(e(__('mail.post_note_added.body', ['author' => 'Ana Author', 'workspace' => 'Acme Workspace'], $locale->value)), false);
    $mailable->assertSeeInHtml(__('mail.post_note_added.button', [], $locale->value));
    $mailable->assertSeeInHtml('Can we swap the image?');
    $mailable->assertSeeInHtml('Launch day is here');
    $mailable->assertSeeInHtml('LinkedIn');
    $mailable->assertSeeInHtml(asset('images/accounts/linkedin.png'));
    $mailable->assertSeeInHtml(route('app.posts.edit', ['post' => $post, 'comment' => $note->id]), false);
})->with([Locale::English, Locale::PortugueseBrazil]);

test('the post note email falls back when the post has no text', function () {
    $author = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $author->id, 'content' => '']);
    $note = PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $author->id]);

    (new PostNoteAdded($note, $author))
        ->assertSeeInHtml(__('mail.post_note_added.post_without_text'));
});

test('the disconnected-connections digest renders every account and reason', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
        'name' => 'Acme Workspace',
    ]);

    $accounts = collect([Platform::LinkedIn, Platform::X])->map(
        fn (Platform $platform) => SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => $platform,
        ]),
    );

    $mailable = (new WorkspaceConnectionsDisconnected($workspace, $accounts))
        ->locale(Locale::German->value);

    $mailable->assertHasSubject(trans_choice(
        'mail.workspace_connections_disconnected.subject',
        2,
        ['count' => 2, 'workspace' => 'Acme Workspace'],
        'de',
    ));

    $mailable->assertSeeInHtml(__('mail.workspace_connections_disconnected.heading', [], 'de'));
    $mailable->assertSeeInHtml(__('mail.workspace_connections_disconnected.reason_revoked', [], 'de'));
    $mailable->assertSeeInHtml('Acme Workspace');

    foreach ($accounts as $account) {
        $mailable->assertSeeInHtml($account->platform->label());
        $mailable->assertSeeInHtml($account->accountDisplayName());
        $mailable->assertSeeInHtml(asset('images/accounts/'.$account->platform->network().'.png'));
    }
});

function sentNotificationHtml(User $user, BaseNotification $notification): string
{
    Mail::mailer()->getSymfonyTransport()->messages()->take(0);

    $user->notify($notification);

    $message = Mail::mailer()->getSymfonyTransport()->messages()->last();

    return (string) $message->getOriginalMessage()->getHtmlBody();
}

test('the verification email is sent in the user locale', function () {
    $user = User::factory()->create(['locale' => Locale::Spanish]);

    expect(sentNotificationHtml($user, new VerifyEmail))
        ->toContain(__('mail.email_verification.body', [], 'es'))
        ->toContain(__('mail.email_verification.button', [], 'es'))
        ->toContain(__('mail.layout.team', [], 'es'))
        ->not->toContain(__('mail.email_verification.body', [], 'en'));
});

test('the password reset email is sent in the user locale', function () {
    $user = User::factory()->create(['locale' => Locale::Japanese]);

    expect(sentNotificationHtml($user, new ResetPassword('token-123')))
        ->toContain(__('mail.password_reset.body', [], 'ja'))
        ->toContain(__('mail.password_reset.expiry', [], 'ja'))
        ->not->toContain(__('mail.password_reset.body', [], 'en'));
});

test('the approval request email renders who asked, the channels, the excerpt and the queue slot', function (Locale $locale) {
    $requester = User::factory()->create(['name' => 'Rita Requester', 'email' => 'rita@example.com']);
    $approver = User::factory()->create(['account_id' => $requester->account_id, 'timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['account_id' => $requester->account_id, 'user_id' => $requester->id, 'name' => 'Acme Workspace']);
    $post = Post::factory()->pendingApproval()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $requester->id,
        'content' => '<p>Big <strong>launch</strong> tomorrow</p>',
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => null,
    ]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Acme Inc']);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::LinkedIn]);

    $mailable = (new PostApprovalRequested([$post->id], $requester, $approver))->locale($locale->value);

    $mailable->assertHasSubject(__('mail.post_approval_requested.subject', ['name' => 'Rita Requester'], $locale->value));
    $mailable->assertSeeInHtml(__('mail.post_approval_requested.heading', [], $locale->value));
    $mailable->assertSeeInHtml(e(__('mail.post_approval_requested.body', ['name' => 'Rita Requester', 'email' => 'rita@example.com', 'workspace' => 'Acme Workspace'], $locale->value)), false);
    $mailable->assertSeeInHtml(__('mail.post_approval_requested.next_queue_slot', [], $locale->value));
    $mailable->assertSeeInHtml(__('mail.post_approval_requested.button', [], $locale->value));
    $mailable->assertSeeInHtml('Big launch tomorrow');
    $mailable->assertSeeInHtml('LinkedIn');
    $mailable->assertSeeInHtml(asset('images/accounts/linkedin.png'));
    $mailable->assertSeeInHtml(route('app.posts.index', ['tab' => 'approvals']), false);
})->with([Locale::English, Locale::PortugueseBrazil]);

test('the approved and rejected emails render the approver, the channels and their button', function (Locale $locale) {
    $author = User::factory()->create(['timezone' => 'UTC']);
    $approver = User::factory()->create(['account_id' => $author->account_id, 'name' => 'Ada Approver']);
    $workspace = Workspace::factory()->create(['account_id' => $author->account_id, 'user_id' => $author->id, 'name' => 'Acme Workspace']);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $author->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => CarbonImmutable::parse('2026-10-07 15:00', 'UTC'),
    ]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn, 'display_name' => 'Acme Inc']);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::LinkedIn]);

    $approved = (new PostApproved([$post->id], $approver, $author))->locale($locale->value);

    $approved->assertHasSubject(__('mail.post_approved.subject', ['name' => 'Ada Approver'], $locale->value));
    $approved->assertSeeInHtml(__('mail.post_approved.heading', [], $locale->value));
    $approved->assertSeeInHtml(e(__('mail.post_approved.body', ['name' => 'Ada Approver', 'workspace' => 'Acme Workspace'], $locale->value)), false);
    $approved->assertSeeInHtml(__('mail.post_approved.goes_out', [], $locale->value));
    $approved->assertSeeInHtml('(UTC)');
    $approved->assertSeeInHtml('LinkedIn');
    $approved->assertSeeInHtml(route('app.posts.index', ['tab' => 'queue']), false);

    $rejected = (new PostRejected([$post->id], $approver))->locale($locale->value);

    $rejected->assertSeeInHtml(asset('images/accounts/linkedin.png'));
    $approved->assertSeeInHtml(asset('images/accounts/linkedin.png'));

    $rejected->assertHasSubject(__('mail.post_rejected.subject', ['name' => 'Ada Approver'], $locale->value));
    $rejected->assertSeeInHtml(e(__('mail.post_rejected.body', ['name' => 'Ada Approver', 'workspace' => 'Acme Workspace'], $locale->value)), false);
    $rejected->assertSeeInHtml(__('mail.post_rejected.button', [], $locale->value));
    $rejected->assertSeeInHtml(route('app.posts.index', ['tab' => 'drafts']), false);
})->with([Locale::English, Locale::PortugueseBrazil]);

/**
 * @param  array<string, mixed>  $attributes
 */
function approvalEmailPost(Workspace $workspace, User $author, Platform $platform, string $displayName, array $attributes = []): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $author->id,
        ...$attributes,
    ]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => $platform, 'display_name' => $displayName]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => $platform]);

    return $post;
}

function approvalEmailTimeLabel(CarbonImmutable $at, User $recipient, Locale $locale): string
{
    $previous = app()->getLocale();
    app()->setLocale($locale->value);
    $label = (string) ApprovalEmailPosts::time($at, $recipient);
    app()->setLocale($previous);

    return $label;
}

test('the approval request email keeps the paragraph breaks of the post', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create(['account_id' => $requester->account_id]);
    $workspace = Workspace::factory()->create(['account_id' => $requester->account_id, 'user_id' => $requester->id]);
    $post = approvalEmailPost($workspace, $requester, Platform::LinkedIn, 'Acme Inc', [
        'status' => PostStatus::PendingApproval,
        'content' => '<p>First paragraph</p><p>Second <strong>paragraph</strong><br>and a line</p>',
    ]);

    $html = (new PostApprovalRequested([$post->id], $requester, $approver))->render();

    expect($html)->toContain("First paragraph\nSecond paragraph\nand a line");
});

test('the post note email keeps the paragraph breaks of the post', function () {
    $author = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $author->id, 'content' => '<p>Tom &amp; Jerry</p><p>are back</p>']);
    $note = PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $author->id]);

    $html = (new PostNoteAdded($note, $author))->render();

    expect($html)->toContain("Tom &amp; Jerry\nare back");
});

test('a queue request holding its slot shows that time, not the next queue slot', function (Locale $locale) {
    $requester = User::factory()->create();
    $approver = User::factory()->create(['account_id' => $requester->account_id, 'timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['account_id' => $requester->account_id, 'user_id' => $requester->id]);
    $at = CarbonImmutable::parse('2026-10-06 09:00', 'UTC');
    $post = approvalEmailPost($workspace, $requester, Platform::LinkedIn, 'Acme Inc', [
        'status' => PostStatus::PendingApproval,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => $at,
    ]);

    $mailable = (new PostApprovalRequested([$post->id], $requester, $approver))->locale($locale->value);

    $mailable->assertSeeInHtml(approvalEmailTimeLabel($at, $approver, $locale))
        ->assertDontSeeInHtml(__('mail.post_approval_requested.next_queue_slot', [], $locale->value));
})->with([Locale::English, Locale::PortugueseBrazil]);

test('a publish now request says it goes out as soon as it is approved', function (Locale $locale) {
    $requester = User::factory()->create();
    $approver = User::factory()->create(['account_id' => $requester->account_id]);
    $workspace = Workspace::factory()->create(['account_id' => $requester->account_id, 'user_id' => $requester->id]);
    $post = approvalEmailPost($workspace, $requester, Platform::LinkedIn, 'Acme Inc', [
        'status' => PostStatus::PendingApproval,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => null,
    ]);

    $mailable = (new PostApprovalRequested([$post->id], $requester, $approver))->locale($locale->value);

    $mailable->assertSeeInHtml(__('mail.post_approval_requested.as_soon_as_approved', [], $locale->value))
        ->assertDontSeeInHtml(__('mail.post_approval_requested.next_queue_slot', [], $locale->value));
})->with([Locale::English, Locale::PortugueseBrazil]);

test('the approved email lists the time of each channel when they go out at different times', function (Locale $locale) {
    $author = User::factory()->create(['timezone' => 'UTC']);
    $approver = User::factory()->create(['account_id' => $author->account_id]);
    $workspace = Workspace::factory()->create(['account_id' => $author->account_id, 'user_id' => $author->id]);
    $morning = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');
    $evening = CarbonImmutable::parse('2026-10-08 18:00', 'UTC');
    $linkedIn = approvalEmailPost($workspace, $author, Platform::LinkedIn, 'Acme Inc', ['status' => PostStatus::Scheduled, 'scheduled_at' => $morning]);
    $x = approvalEmailPost($workspace, $author, Platform::X, 'acmex', ['status' => PostStatus::Scheduled, 'scheduled_at' => $evening]);
    $mastodon = approvalEmailPost($workspace, $author, Platform::Mastodon, 'acmetoot', ['status' => PostStatus::Publishing, 'scheduled_at' => null]);

    $mailable = (new PostApproved([$linkedIn->id, $x->id, $mastodon->id], $approver, $author))->locale($locale->value);

    $mailable->assertSeeInOrderInHtml([
        $linkedIn->postPlatforms()->sole()->display_name,
        approvalEmailTimeLabel($morning, $author, $locale),
        $x->postPlatforms()->sole()->display_name,
        approvalEmailTimeLabel($evening, $author, $locale),
        $mastodon->postPlatforms()->sole()->display_name,
        __('mail.post_approved.publishing_now', [], $locale->value),
    ]);
    $document = new Crawler($mailable->render());
    foreach ([Platform::LinkedIn, Platform::X, Platform::Mastodon] as $platform) {
        expect($document->filter('img[src="'.asset('images/accounts/'.$platform->network().'.png').'"]')->count())->toBe(1);
    }
})->with([Locale::English, Locale::PortugueseBrazil, Locale::French]);

test('the approved email shows one time when every channel goes out together', function () {
    $author = User::factory()->create(['timezone' => 'UTC']);
    $approver = User::factory()->create(['account_id' => $author->account_id]);
    $workspace = Workspace::factory()->create(['account_id' => $author->account_id, 'user_id' => $author->id]);
    $at = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');
    $linkedIn = approvalEmailPost($workspace, $author, Platform::LinkedIn, 'Acme Inc', ['status' => PostStatus::Scheduled, 'scheduled_at' => $at]);
    $x = approvalEmailPost($workspace, $author, Platform::X, 'acmex', ['status' => PostStatus::Scheduled, 'scheduled_at' => $at]);

    $html = (new PostApproved([$linkedIn->id, $x->id], $approver, $author))->render();

    expect(substr_count($html, "{$at->locale('en')->isoFormat('LLL')} (UTC)"))->toBe(1);
});

test('approval emails show the time in the recipient zone and clock', function (TimeFormat $format, Locale $locale, string $expected) {
    $author = User::factory()->create(['timezone' => 'America/Sao_Paulo', 'time_format' => $format]);
    $approver = User::factory()->create(['account_id' => $author->account_id]);
    $workspace = Workspace::factory()->create(['account_id' => $author->account_id, 'user_id' => $author->id]);
    $post = approvalEmailPost($workspace, $author, Platform::LinkedIn, 'Acme Inc', [
        'status' => PostStatus::Scheduled,
        'scheduled_at' => CarbonImmutable::parse('2026-10-07 15:00', 'UTC'),
    ]);

    $html = (new PostApproved([$post->id], $approver, $author))->locale($locale->value)->render();

    expect($html)->toContain($expected);
})->with([
    'english 12-hour' => [TimeFormat::TwelveHour, Locale::English, 'October 7, 2026 12:00 PM (America/Sao_Paulo)'],
    'english 24-hour' => [TimeFormat::TwentyFourHour, Locale::English, 'October 7, 2026 12:00 (America/Sao_Paulo)'],
    'german 24-hour' => [TimeFormat::TwentyFourHour, Locale::German, '7. Oktober 2026 12:00 (America/Sao_Paulo)'],
]);

test('approval emails name the canonical zone of a legacy alias', function () {
    $author = User::factory()->create(['timezone' => 'Asia/Calcutta', 'time_format' => TimeFormat::TwentyFourHour]);
    $approver = User::factory()->create(['account_id' => $author->account_id]);
    $workspace = Workspace::factory()->create(['account_id' => $author->account_id, 'user_id' => $author->id]);
    $post = approvalEmailPost($workspace, $author, Platform::LinkedIn, 'Acme Inc', [
        'status' => PostStatus::Scheduled,
        'scheduled_at' => CarbonImmutable::parse('2026-10-07 15:00', 'UTC'),
    ]);

    expect((new PostApproved([$post->id], $approver, $author))->render())->toContain('October 7, 2026 20:30 (Asia/Kolkata)');
});

test('the email layout carries the recipient locale and direction', function (Locale $locale, string $direction) {
    $account = Account::factory()->create(['name' => 'Acme Co']);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
        'email' => 'invitee@example.com',
        ...membershipPivot('approval'),
    ]);

    $mailable = (new WorkspaceInvite($invite))->locale($locale->value);

    $mailable->assertSeeInHtml("<html lang=\"{$locale->value}\" dir=\"{$direction}\"", false);
})->with([
    'arabic' => [Locale::Arabic, 'rtl'],
    'portuguese' => [Locale::PortugueseBrazil, 'ltr'],
]);

test('approval emails keep distinct channels with the same name and escape their names', function () {
    $author = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['account_id' => $author->account_id, 'user_id' => $author->id]);
    $first = approvalEmailPost($workspace, $author, Platform::Instagram, 'Studio <Team>');
    $second = approvalEmailPost($workspace, $author, Platform::Instagram, 'Studio <Team>');

    $html = (new PostRejected([$first->id, $second->id], $author))->render();
    $document = new Crawler($html);

    expect($document->filter('img[src="'.asset('images/accounts/instagram.png').'"]')->count())->toBe(2)
        ->and($html)->toContain('Studio &lt;Team&gt;')
        ->not->toContain('Studio <Team>');
});
