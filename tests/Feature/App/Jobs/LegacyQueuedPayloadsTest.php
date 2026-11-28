<?php

declare(strict_types=1);

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Mail\AccountDisconnected;
use App\Mail\PostAtRisk;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

class LegacyMainSendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public User $user,
        public string $workspaceId,
        public Type $type,
        public Channel $channel,
        public string $title,
        public string $body,
        public ?array $data = null,
        public ?Mailable $mailable = null,
    ) {}
}

class LegacyMainMentionedInComment extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public PostNote $comment, public User $author, public string $excerpt) {}
}

class LegacyMainNotification extends Model
{
    protected $table = 'users';
}

class LegacyMainNotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Model $notification) {}

    public function broadcastOn(): array
    {
        return [];
    }
}

class LegacyMainPostCommentCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Model $comment) {}

    public function broadcastOn(): array
    {
        return [];
    }
}

class LegacyMainPostCreationReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $userId, public string $creationId, public ?string $postId = null, public ?string $error = null) {}

    public function broadcastOn(): array
    {
        return [];
    }
}

class LegacyMainPostMediaRegenerated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $media
     */
    public function __construct(public string $userId, public string $regenerationId, public string $postId, public ?array $media = null, public ?string $error = null) {}

    public function broadcastOn(): array
    {
        return [];
    }
}

class LegacyMainTelegramChannelConnected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $workspaceId, public string $nonce) {}

    public function broadcastOn(): array
    {
        return [];
    }
}

/**
 * @param  array<string, string>  $renames
 */
function serializeAsMain(object $command, array $renames): string
{
    $serialized = serialize($command);

    foreach ($renames as $from => $to) {
        $serialized = preg_replace(
            '/([Os]):\\d+:"'.preg_quote($from, '/').'"/',
            '$1:'.strlen($to).':"'.addcslashes($to, '\\$').'"',
            $serialized,
        );
    }

    return $serialized;
}

/**
 * @param  array<string, string>  $renames
 */
function runMainQueuedPayload(object $command, array $renames): FakeJob
{
    $job = new FakeJob;

    app(CallQueuedHandler::class)->call($job, ['command' => serializeAsMain($command, $renames)]);

    return $job;
}

beforeEach(function () {
    Mail::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
});

test('a SendNotification queued by main still sends its email', function (Type $type, Closure $makeMailable) {
    $mailable = $makeMailable($this->user, $this->workspace);

    $job = runMainQueuedPayload(new LegacyMainSendNotification(
        user: $this->user,
        workspaceId: $this->workspace->id,
        type: $type,
        channel: Channel::Both,
        title: 'Title',
        body: 'Body',
        data: ['post_id' => 'legacy'],
        mailable: $mailable,
    ), [LegacyMainSendNotification::class => 'App\Jobs\SendNotification']);

    expect($job->hasFailed())->toBeFalse();
    Mail::assertQueued($mailable::class, fn (Mailable $mail): bool => $mail->hasTo($this->user->email));
})->with([
    'post published' => [Type::PostPublished, fn (User $user, Workspace $workspace): Mailable => new PostPublished(
        Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]),
    )],
    'post failed' => [Type::PostFailed, fn (User $user, Workspace $workspace): Mailable => new PostPublishFailed(
        Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]),
    )],
    'account disconnected' => [Type::AccountDisconnected, fn (User $user, Workspace $workspace): Mailable => new AccountDisconnected(
        SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]),
    )],
    'workspace connections disconnected' => [Type::AccountDisconnected, fn (User $user, Workspace $workspace): Mailable => new WorkspaceConnectionsDisconnected(
        $workspace,
        collect([SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id])]),
    )],
    'post at risk' => [Type::PostAtRisk, fn (User $user, Workspace $workspace): Mailable => new PostAtRisk($workspace, [], 1)],
]);

test('a SendNotification queued by main still honours the email preference', function () {
    $this->user->notificationPreference()->create(['post_published' => false]);

    $job = runMainQueuedPayload(new LegacyMainSendNotification(
        user: $this->user,
        workspaceId: $this->workspace->id,
        type: Type::PostPublished,
        channel: Channel::Both,
        title: 'Title',
        body: 'Body',
        mailable: new PostPublished(Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id])),
    ), [LegacyMainSendNotification::class => 'App\Jobs\SendNotification']);

    expect($job->hasFailed())->toBeFalse();
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

test('in-app only and mention notifications queued by main finish without sending anything', function (Type $type, Channel $channel, bool $withMention) {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $mailable = $withMention
        ? new LegacyMainMentionedInComment(PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $this->user->id]), $this->user, 'Hey')
        : null;

    $job = runMainQueuedPayload(new LegacyMainSendNotification(
        user: $this->user,
        workspaceId: $this->workspace->id,
        type: $type,
        channel: $channel,
        title: 'Title',
        body: 'Body',
        data: ['post_id' => $post->id],
        mailable: $mailable,
    ), [
        LegacyMainSendNotification::class => 'App\Jobs\SendNotification',
        LegacyMainMentionedInComment::class => 'App\Mail\MentionedInComment',
        PostNote::class => 'App\Models\PostComment',
    ]);

    expect($job->hasFailed())->toBeFalse();
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
})->with([
    'post ready, in-app' => [Type::PostReady, Channel::InApp, false],
    'mention, recipient online' => [Type::MentionedInComment, Channel::InApp, false],
    'mention, recipient offline' => [Type::MentionedInComment, Channel::Both, true],
    'post published, in-app' => [Type::PostPublished, Channel::InApp, false],
]);

test('a mention email queued by main is dropped without failing', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $note = PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $this->user->id]);

    $job = runMainQueuedPayload(new SendQueuedMailable(new LegacyMainMentionedInComment($note, $this->user, 'Hey')), [
        LegacyMainMentionedInComment::class => 'App\Mail\MentionedInComment',
        PostNote::class => 'App\Models\PostComment',
    ]);

    expect($job->hasFailed())->toBeFalse();
    Mail::assertNothingSent();
});

test('broadcasts of removed events queued by main finish without broadcasting', function (Closure $makeEvent, array $renames) {
    $event = new BroadcastEvent($makeEvent($this->user, $this->workspace));
    $broadcaster = Mockery::mock(BroadcastingFactory::class);
    $broadcaster->shouldNotReceive('connection');
    $this->app->instance(BroadcastingFactory::class, $broadcaster);

    $job = runMainQueuedPayload($event, $renames);

    expect($job->hasFailed())->toBeFalse();
})->with([
    'notification created' => [
        fn (User $user): object => new LegacyMainNotificationCreated(LegacyMainNotification::query()->findOrFail($user->id)),
        [LegacyMainNotificationCreated::class => 'App\Events\NotificationCreated', LegacyMainNotification::class => 'App\Models\Notification'],
    ],
    'post comment created' => [
        fn (User $user, Workspace $workspace): object => new LegacyMainPostCommentCreated(PostNote::factory()->create([
            'post_id' => Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id])->id,
            'user_id' => $user->id,
        ])),
        [LegacyMainPostCommentCreated::class => 'App\Events\PostCommentCreated', PostNote::class => 'App\Models\PostComment'],
    ],
    'ai post creation ready' => [
        fn (User $user): object => new LegacyMainPostCreationReady($user->id, 'creation', 'post'),
        [LegacyMainPostCreationReady::class => 'App\Events\Ai\PostCreationReady'],
    ],
    'ai post media regenerated' => [
        fn (User $user): object => new LegacyMainPostMediaRegenerated($user->id, 'regeneration', 'post', ['id' => 'media']),
        [LegacyMainPostMediaRegenerated::class => 'App\Events\Ai\PostMediaRegenerated'],
    ],
]);

test('a telegram connected broadcast queued by main still broadcasts its nonce', function () {
    $broadcasts = [];
    $connection = Mockery::mock();
    $connection->shouldReceive('broadcast')->once()->andReturnUsing(function (array $channels, string $name, array $payload) use (&$broadcasts): void {
        $broadcasts[] = compact('channels', 'name', 'payload');
    });
    $broadcaster = Mockery::mock(BroadcastingFactory::class);
    $broadcaster->shouldReceive('connection')->andReturn($connection);
    $this->app->instance(BroadcastingFactory::class, $broadcaster);

    $job = runMainQueuedPayload(new BroadcastEvent(new LegacyMainTelegramChannelConnected($this->workspace->id, 'nonce-1')), [
        LegacyMainTelegramChannelConnected::class => 'App\Events\TelegramChannelConnected',
    ]);

    expect($job->hasFailed())->toBeFalse()
        ->and($broadcasts)->toHaveCount(1)
        ->and($broadcasts[0]['name'])->toBe('telegram.channel.connected')
        ->and($broadcasts[0]['payload'])->toMatchArray(['nonce' => 'nonce-1', 'account_id' => null, 'created' => false]);
});

test('no application code iterates the notification types', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file) => [$file->getPathname(), File::get($file->getPathname())])
        ->filter(fn (array $source) => str_contains($source[1], 'App\\Enums\\Notification\\Type') && preg_match('/Type::cases\\(\\)/', $source[1]) === 1)
        ->map(fn (array $source) => $source[0]);

    expect($offenders)->toBeEmpty();
});
