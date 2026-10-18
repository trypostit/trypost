<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Inbox\StoreInboxMedia;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\MessageLimit;
use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\Plan\Slug;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Inbox\StoreInboxMediaJob;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\HostResolver;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\InboxDrivers;
use App\Support\Social\PendingConnection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\BrowserTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        fakePwnedPasswords();
        fakeChatGptEgressRanges();
        Queue::fake([BootstrapAccountAnalytics::class, StoreInboxMediaJob::class]);
    })
    ->in('Feature', 'Unit');

pest()->extend(BrowserTestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => fakePwnedPasswords())
    ->in('Browser');

pest()->in('Feature/Social')
    ->beforeEach(fn () => Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]));

/*
|--------------------------------------------------------------------------
| Test Impact Analysis
|--------------------------------------------------------------------------
|
| Only re-run tests affected by local changes, replaying cached results for
| the rest. Scoped to local runs via "locally()" — automatically skipped on
| CI (or when the "--ci" flag is passed), which always runs the full suite.
|
*/

pest()->tia()->locally();

/*
|--------------------------------------------------------------------------
| Browser Timeout
|--------------------------------------------------------------------------
|
| CI runs the browser suite with four processes per runner, so an action can
| wait longer than the plugin's 5 seconds before its element is ready. This is
| the ceiling of each wait, not a delay.
|
*/

pest()->browser()->timeout(15000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Runs the copy of every inbox file still waiting, as the queued
 * StoreInboxMediaJob would (it is faked in every feature test).
 */
function storeAllInboxMedia(): void
{
    InboxMessage::query()->get()->each(fn (InboxMessage $message): bool => StoreInboxMedia::execute($message, true, ImportRemoteMedia::timeoutSeconds()));
    InboxConversation::query()->get()->each(fn (InboxConversation $conversation): bool => StoreInboxMedia::execute($conversation, true, ImportRemoteMedia::timeoutSeconds()));
}

function inboxIncoming(string $id, ?string $parent = null, MessageDirection $direction = MessageDirection::Inbound, string $at = '2026-10-16 10:00:00'): IncomingMessage
{
    return new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject('at://did:plc:me/app.bsky.feed.post/root1', 'My post', [], 'https://bsky.app/profile/me/post/root1', CarbonImmutable::parse('2026-10-15'), 'root1'),
        platformMessageId: $id,
        platformParentId: $parent,
        direction: $direction,
        authorPlatformId: $direction === MessageDirection::Outbound ? 'did:plc:me' : 'did:plc:fan',
        authorName: 'Fan',
        authorUsername: 'fan.bsky.social',
        authorAvatarUrl: null,
        body: 'Nice post',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse($at),
    );
}

function inboxMention(string $id, ?string $parent = null, MessageDirection $direction = MessageDirection::Inbound, string $subjectId = 'at://did:plc:fan/app.bsky.feed.post/m1', string $at = '2026-10-16 10:00:00'): IncomingMessage
{
    return new IncomingMessage(
        kind: ConversationKind::Mention,
        subject: new IncomingSubject($subjectId, 'Hey @me look', [], 'https://bsky.app/profile/fan/post/m1', CarbonImmutable::parse('2026-10-16'), null, [], 'Fan', 'fan.bsky.social', null),
        platformMessageId: $id,
        platformParentId: $parent,
        direction: $direction,
        authorPlatformId: $direction === MessageDirection::Outbound ? 'did:plc:me' : 'did:plc:fan',
        authorName: 'Fan',
        authorUsername: 'fan.bsky.social',
        authorAvatarUrl: null,
        body: 'Hey @me look',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse($at),
    );
}

function inboxBlueskyJwt(string $scope): string
{
    $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

    return "{$encode(['alg' => 'ES256K', 'typ' => 'at+jwt'])}.{$encode(['scope' => $scope, 'sub' => 'did:plc:me'])}.signature";
}

function fakeInboxDriver(InboxDriver $driver): void
{
    app()->instance(InboxDrivers::class, new class($driver) extends InboxDrivers
    {
        public function __construct(private InboxDriver $driver) {}

        public function for(Platform $platform): ?InboxDriver
        {
            return $this->driver;
        }
    });
}

function fakeDirectMessageDriver(?int $windowHours = 24, int $max = 1000, ReplyLengthUnit $unit = ReplyLengthUnit::Bytes): InboxDriver&DirectMessageDriver
{
    $driver = new class($windowHours, $max, $unit) implements DirectMessageDriver, InboxDriver
    {
        /** @var list<array{conversation: string, text: string}> */
        public array $sent = [];

        public function __construct(private ?int $windowHours, private int $max, private ReplyLengthUnit $unit) {}

        public function kinds(): array
        {
            return [ConversationKind::Comment, ConversationKind::DirectMessage];
        }

        public function poll(SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): PollResult
        {
            return new PollResult([], $cursor);
        }

        public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
        {
            return new SentReply('reply-'.Str::random(6), null, CarbonImmutable::now());
        }

        public function replyLength(SocialAccount $account): int
        {
            return 1000;
        }

        public function replyLengthUnit(): ReplyLengthUnit
        {
            return ReplyLengthUnit::Characters;
        }

        public function prefillsMention(): bool
        {
            return false;
        }

        public function sendDirectMessage(SocialAccount $account, InboxConversation $conversation, string $text): SentReply
        {
            $this->sent[] = ['conversation' => $conversation->id, 'text' => $text];

            return new SentReply('mid-'.count($this->sent), null, CarbonImmutable::now());
        }

        public function directMessageText(InboxConversation $conversation, string $text): string
        {
            return $text;
        }

        public function directMessageLimit(SocialAccount $account): MessageLimit
        {
            return new MessageLimit($this->max, $this->unit);
        }

        public function hasReplyWindow(): bool
        {
            return $this->windowHours !== null;
        }

        public function replyWindowEndsAt(?CarbonImmutable $lastInboundAt): ?CarbonImmutable
        {
            return $this->windowHours === null ? null : $lastInboundAt?->addHours($this->windowHours);
        }
    };

    fakeInboxDriver($driver);

    return $driver;
}

function inboxDirectIncoming(string $id, MessageDirection $direction = MessageDirection::Inbound, string $at = '2026-10-16 10:00:00', string $participant = 'igsid-1', bool $removed = false): IncomingMessage
{
    $isOutbound = $direction === MessageDirection::Outbound;

    return new IncomingMessage(
        kind: ConversationKind::DirectMessage,
        subject: new IncomingSubject($participant, null, [], null, null, null, [], 'Ana Fan', 'ana.fan', 'https://cdn.example/ana.jpg'),
        platformMessageId: $id,
        platformParentId: null,
        direction: $direction,
        authorPlatformId: $isOutbound ? 'ig-me' : $participant,
        authorName: $isOutbound ? 'Me' : 'Ana Fan',
        authorUsername: $isOutbound ? 'me' : 'ana.fan',
        authorAvatarUrl: null,
        body: 'Hi there',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse($at),
        removed: $removed,
    );
}

/**
 * Issue a real Passport personal access token bound to a workspace and return
 * the plain JWT string. Use the returned token in `Authorization: Bearer ...`
 * to exercise the auth:api + workspace.token middleware stack.
 */
function strongPassword(): string
{
    return 'Tr7#pass-Word-2026';
}

function compromisedPassword(): string
{
    return 'Compromised#Pass-2026';
}

function fakePwnedPasswords(): void
{
    $hash = strtoupper(sha1(compromisedPassword()));

    Http::fake([
        'api.pwnedpasswords.com/range/*' => fn (HttpClientRequest $request) => Http::response(
            str_ends_with($request->url(), substr($hash, 0, 5)) ? substr($hash, 5).':42' : '',
        ),
    ]);
}

/**
 * OpenAI's published ChatGPT egress ranges, answered from the
 * testing.chatgpt_egress_ranges config so a test can change them after the
 * global fake is registered. null answers a failed read.
 *
 * @param  list<mixed>|null  $ranges
 */
function fakeChatGptEgressRanges(?array $ranges = []): void
{
    config(['testing.chatgpt_egress_ranges' => $ranges]);

    Http::fake([
        config('trypost.mcp_chatgpt_ip_ranges_url') => fn () => config('testing.chatgpt_egress_ranges') === null
            ? Http::response('', 500)
            : Http::response([
                'prefixes' => collect(config('testing.chatgpt_egress_ranges'))->map(fn (mixed $range): array => ['ipv4Prefix' => $range])->all(),
            ]),
    ]);
}

function passportToken(User $user, Workspace $workspace, array $scopes = []): string
{
    $result = $user->createToken('Test', $scopes);

    AccessToken::find($result->token->id)
        ->forceFill(['workspace_id' => $workspace->id])
        ->saveQuietly();

    return $result->accessToken;
}

/**
 * Create a workspace + owner + Passport token suitable for hitting the public
 * API. Drop-in replacement for the legacy `createXApiToken` helpers.
 *
 * @param  array{workspace?: Workspace}  $overrides
 * @return array{plain_token: string, workspace: Workspace, user: User}
 */
function createApiTestToken(array $overrides = []): array
{
    $workspace = data_get($overrides, 'workspace');

    if (! $workspace) {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, membershipPivot('admin'));
        $user->update(['current_workspace_id' => $workspace->id]);
    } else {
        $user = $workspace->owner ?? User::factory()->create([
            'account_id' => $workspace->account_id,
        ]);

        if ($workspace->account && $workspace->account->owner_id !== $user->id) {
            $workspace->account->update(['owner_id' => $user->id]);
        }
    }

    return [
        'plain_token' => passportToken($user, $workspace),
        'workspace' => $workspace,
        'user' => $user,
    ];
}

/**
 * Pivot columns for a workspace membership: an admin, a member who publishes
 * directly, or a member whose posts need approval.
 *
 * @return array{is_admin: bool, requires_approval: bool}
 */
function membershipPivot(string $access): array
{
    return match ($access) {
        'admin' => ['is_admin' => true, 'requires_approval' => false],
        'member' => ['is_admin' => false, 'requires_approval' => false],
        'approval' => ['is_admin' => false, 'requires_approval' => true],
    };
}

/**
 * A user on the workspace's account, attached with the given access and
 * switched to that workspace.
 *
 * @param  array<string, mixed>  $attributes
 */
function workspaceMember(Workspace $workspace, string $access = 'member', array $attributes = []): User
{
    $user = User::factory()->create(['account_id' => $workspace->account_id, ...$attributes]);
    $workspace->members()->attach($user->id, membershipPivot($access));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

/**
 * A user on the workspace's account whose current workspace is this one but
 * who is not a member of it.
 */
function workspaceOutsider(Workspace $workspace): User
{
    $user = User::factory()->create(['account_id' => $workspace->account_id]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

/**
 * Create an account on the Workspace plan with an active subscription on the
 * given Stripe price, plus N workspaces. Used by the billing-cycle tests.
 *
 * @param  array<string, mixed>  $subscriptionAttributes
 */
function billingAccount(string $price, array $subscriptionAttributes = [], int $workspaces = 1): Account
{
    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update([
        'stripe_monthly_price_id' => 'price_month',
        'stripe_yearly_price_id' => 'price_year',
    ]);

    $account = Account::factory()->create([
        'plan_id' => $plan->id,
        'trial_ends_at' => null,
    ]);

    $account->subscriptions()->create(array_merge([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => $price,
        'quantity' => $workspaces,
    ], $subscriptionAttributes));

    Workspace::factory()->count($workspaces)->create(['account_id' => $account->id]);

    return $account->refresh();
}

/**
 * The session a connect start route leaves behind: the network, the workspace,
 * the card being reconnected and where to return.
 */
function startSocialConnect(Workspace|string $workspace, Platform $platform, SocialAccount|string|null $reconnect = null, ?string $returnTo = null): PendingConnection
{
    $workspace = $workspace instanceof Workspace ? $workspace : (new Workspace)->forceFill(['id' => $workspace]);

    return PendingConnection::start($platform, $workspace, $reconnect instanceof SocialAccount ? $reconnect->id : $reconnect, $returnTo);
}

/**
 * "Finish connection" on the confirmation page, with every offered identity not
 * already connected unless a selection is given.
 *
 * @param  array<int, string>|null  $identities
 */
function finishSocialConnect(Platform $platform, ?array $identities = null): TestResponse
{
    return test()->post(route('app.social.connect.finish', $platform), [
        'identities' => $identities ?? array_values(array_diff(
            PendingConnection::current()?->identityKeys() ?? [],
            PendingConnection::current()?->lockedIdentityKeys() ?? [],
        )),
    ]);
}

/**
 * Finish always lands on the publish page of the channel it connected: the given
 * one, else any channel of the test workspace.
 */
function assertFinishedOnChannel(TestResponse $response, ?SocialAccount $channel = null): TestResponse
{
    if ($channel !== null) {
        return $response->assertRedirect(route('app.channels.publish', $channel));
    }

    $location = (string) $response->headers->get('Location');

    preg_match('#/channels/([^/]+)/publish$#', $location, $matches);

    expect(SocialAccount::query()->whereKey($matches[1] ?? '')->exists())->toBeTrue("Finish redirected to {$location}");

    return $response;
}

/**
 * Why the pending connection stopped, as the confirmation page reads it.
 */
function socialConnectFailure(): ?string
{
    return PendingConnection::current()?->failure();
}

/**
 * Every host name resolves to one public documentation address, so a fetch
 * through SafeHttpFetcher never depends on real DNS. IP literals are still
 * vetted by the guard itself.
 */
function fakePublicDns(string $address = '93.184.216.34'): void
{
    test()->mock(HostResolver::class)->shouldReceive('addresses')->andReturn([$address]);
}

/**
 * Attach an active default subscription to the given account.
 */
function subscribeAccount(Account $account): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
}

/**
 * Insert an OAuth client suitable for MCP connection tests.
 */
function mcpOauthClient(string $name = 'My Agent'): string
{
    $id = (string) Str::uuid();

    DB::table('oauth_clients')->insert([
        'id' => $id,
        'name' => $name,
        'secret' => null,
        'provider' => null,
        'redirect_uris' => '[]',
        'grant_types' => json_encode(['authorization_code', 'refresh_token']),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @return array<string, string>
 */
function oauthAuthorizeQuery(
    string $clientId,
    string $redirectUri = 'https://client.example/callback',
    string $prompt = 'consent',
): array {
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'test-state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'prompt' => $prompt,
    ];
}

/**
 * Create an active OAuth access token for MCP connection tests.
 *
 * @param  list<string>  $scopes
 */
function mcpAccessToken(
    User $user,
    string $clientId,
    ?Workspace $workspace = null,
    array $scopes = ['mcp:use'],
): AccessToken {
    $token = new AccessToken;
    $token->forceFill([
        'id' => Str::random(80),
        'user_id' => $user->id,
        'client_id' => $clientId,
        'workspace_id' => $workspace?->id,
        'name' => 'MCP',
        'scopes' => $scopes,
        'revoked' => false,
        'expires_at' => now()->addYear(),
    ])->save();

    return $token->refresh();
}

/**
 * Issue a Passport token, attach it to a dedicated MCP OAuth client, and bind
 * it to a workspace — the post-#222 shape used by middleware / MCP endpoint tests.
 *
 * @param  list<string>  $scopes
 * @return array{token: AccessToken, plain_token: string}
 */
function mcpBearerToken(User $user, Workspace $workspace, array $scopes = ['mcp:use']): array
{
    $result = $user->createToken('MCP', $scopes);
    $token = AccessToken::query()->findOrFail($result->token->id);

    // Reassign to a dedicated MCP client so we never mutate Passport's shared
    // personal-access client (which would poison PAT fixtures in the same run).
    $token->forceFill([
        'client_id' => mcpOauthClient(),
        'workspace_id' => $workspace->id,
    ])->saveQuietly();

    return [
        'token' => $token->refresh(),
        'plain_token' => $result->accessToken,
    ];
}

/**
 * Move a member onto a shared account (stranded-member / invitee fixture).
 *
 * @return array{
 *     owner: User,
 *     member: User,
 *     shared_workspaces: list<Workspace>
 * }
 */
function strandedMemberOnSharedAccount(
    int $sharedWorkspaces = 0,
    bool $attachMember = true,
    bool $attachMemberToAll = true,
    bool $setMemberCurrent = false,
    ?User $owner = null,
    ?string $memberEmail = null,
): array {
    $owner ??= User::factory()->create();
    $member = User::factory()->create(array_filter([
        'email' => $memberEmail,
    ]));

    // Closed-account model: the member's empty signup shell is gone after
    // accepting the invite, so drop it here to match the real state.
    $member->account?->delete();

    $shared = [];

    for ($i = 0; $i < $sharedWorkspaces; $i++) {
        $workspace = Workspace::factory()->create([
            'account_id' => $owner->account_id,
            'user_id' => $owner->id,
        ]);

        $workspace->members()->syncWithoutDetaching([
            $owner->id => membershipPivot('admin'),
        ]);

        if ($attachMember && ($attachMemberToAll || $i === 0)) {
            $workspace->members()->attach($member->id, membershipPivot('member'));
        }

        $shared[] = $workspace;
    }

    $member->update([
        'account_id' => $owner->account_id,
        'current_workspace_id' => ($setMemberCurrent && $shared !== [])
            ? $shared[0]->id
            : null,
    ]);

    return [
        'owner' => $owner->fresh(),
        'member' => $member->fresh(),
        'shared_workspaces' => $shared,
    ];
}

/**
 * A workspace reachable both through a REST API token and as the MCP actor,
 * for tests that run one scenario through both surfaces.
 *
 * @return array{user: User, workspace: Workspace, token: string}
 */
function parityContext(): array
{
    $result = createApiTestToken();

    return [
        'user' => $result['user']->fresh(),
        'workspace' => $result['workspace'],
        'token' => $result['plain_token'],
    ];
}

/**
 * @return array<string, string>
 */
function parityApi(string $token): array
{
    return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
}

/**
 * The structured content an MCP list tool returns for the same page as a paginated API list response.
 *
 * @return array<string, mixed>
 */
function parityMcpPage(string $key, TestResponse $api): array
{
    return [
        $key => $api->json('data'),
        'total' => $api->json('meta.total'),
        'per_page' => $api->json('meta.per_page'),
        'current_page' => $api->json('meta.current_page'),
        'last_page' => $api->json('meta.last_page'),
    ];
}

/**
 * Wait until the Inter web font is loaded, so layout assertions measure the
 * font users see instead of whichever fallback the operating system has.
 */
function waitForWebFonts(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            const interFaces = () => [...document.fonts].filter((face) => face.family.replaceAll('"', '') === 'Inter');
            for (let attempt = 0; attempt < 100 && interFaces().length === 0; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            await Promise.all(interFaces().map((face) => face.load().catch(() => null)));
            await document.fonts.ready;
        })();
    JS);
}

/**
 * Wait until a JavaScript expression is true in the page, for up to 15
 * seconds, and fail the test when it never is. Assertions do not wait for
 * the SPA to paint, and a blocking sleep() would starve the server that
 * serves the page, so the page polls and reports whether it got there. A
 * full page load during the wait restarts the poll on the new page.
 */
function waitForCondition(mixed $page, string $condition, ?string $description = null): void
{
    $deadline = microtime(true) + 15;

    do {
        $remaining = max(0, (int) (($deadline - microtime(true)) * 1000));

        try {
            $met = $page->script(<<<JS
                (async () => {
                    const deadline = Date.now() + {$remaining};
                    while (Date.now() < deadline) {
                        if ({$condition}) return true;
                        await new Promise((resolve) => setTimeout(resolve, 50));
                    }
                    return Boolean({$condition});
                })();
            JS);
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'Execution context was destroyed') || microtime(true) >= $deadline) {
                throw $exception;
            }

            $met = null;
        }
    } while ($met === null);

    if ($met !== true) {
        $description ??= $condition;
        $caller = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
            ->first(fn (array $frame): bool => str_contains($frame['file'] ?? '', '/tests/Browser/'));

        if ($caller) {
            $file = Str::after($caller['file'], base_path('/'));
            $description .= " ({$file}:{$caller['line']})";
        }

        Assert::fail("Timed out after 15s waiting for: {$description}");
    }
}

/**
 * The selector as a JavaScript string, with `@name` standing for
 * `[data-testid="name"]` as in `$page->click('@name')`, anywhere in it:
 * `@channel-settings-rows [role="combobox"]`.
 */
function jsSelector(string $selector): string
{
    return json_encode(preg_replace('/@([\w-]+)/', '[data-testid="$1"]', $selector));
}

/**
 * Wait until an element matching the selector exists and the expression,
 * written about `element`, is true: "element.getAttribute('aria-checked') === 'true'".
 */
function waitForState(mixed $page, string $selector, string $expression): void
{
    $description = $selector;
    $selector = jsSelector($selector);

    waitForCondition($page, "((element) => Boolean(element && ({$expression})))(document.querySelector({$selector}))", "{$description} to match {$expression}");
}

/**
 * Wait until an element matching the selector is mounted and laid out.
 */
function waitFor(mixed $page, string $selector): void
{
    $description = $selector;
    $selector = jsSelector($selector);

    waitForCondition($page, "((element) => Boolean(element && element.getBoundingClientRect().height > 0))(document.querySelector({$selector}))", "{$description} to be shown");
}

/**
 * Wait until no element matches the selector.
 */
function waitForGone(mixed $page, string $selector): void
{
    $description = $selector;
    $selector = jsSelector($selector);

    waitForCondition($page, "!document.querySelector({$selector})", "{$description} to be gone");
}

/**
 * Wait until any element matching the selector contains the text.
 */
function waitForText(mixed $page, string $selector, string $text): void
{
    $description = "{$selector} to contain \"{$text}\"";
    $selector = jsSelector($selector);
    $text = json_encode($text);

    waitForCondition($page, "[...document.querySelectorAll({$selector})].some((element) => element.textContent.includes({$text}))", $description);
}

/**
 * Wait until one element is gone or another is shown, whichever happens
 * first, so the assertion after it fails at once on the other outcome
 * instead of waiting out the timeout.
 */
function waitForGoneOrShown(mixed $page, string $gone, string $shown): void
{
    $description = "{$gone} to be gone or {$shown} to be shown";
    $gone = jsSelector($gone);
    $shown = jsSelector($shown);

    waitForCondition($page, "!document.querySelector({$gone}) || Boolean(document.querySelector({$shown}))", $description);
}

/**
 * Wait until the page is at this path.
 */
function waitForPath(mixed $page, string $path): void
{
    $description = "the page to be at {$path}";
    $path = json_encode($path);

    waitForCondition($page, "location.pathname === {$path}", $description);
}

/**
 * A JavaScript expression, about `element`, true once the element is laid out
 * and fully inside the viewport. Pass it to waitForState().
 */
function inViewport(): string
{
    return '(r => r.width > 0 && r.height > 0 && r.top >= 0 && r.left >= 0 && r.bottom <= window.innerHeight && r.right <= window.innerWidth)(element.getBoundingClientRect())';
}

/**
 * Wait until both elements exist and the expression, written about their
 * boxes `a` and `b` (DOMRect), is true: "a.bottom <= b.top".
 */
function waitForLayout(mixed $page, string $first, string $second, string $expression): void
{
    $description = "{$first} and {$second} to match {$expression}";
    $first = jsSelector($first);
    $second = jsSelector($second);

    waitForCondition($page, "((x, y) => Boolean(x && y && ((a, b) => {$expression})(x.getBoundingClientRect(), y.getBoundingClientRect())))(document.querySelector({$first}), document.querySelector({$second}))", $description);
}

/**
 * Deliver a broadcast to the page as if it came over the workspace channel,
 * once the page listens on it (tests run with the null broadcaster).
 *
 * @param  array<string, mixed>  $payload
 */
function emitWorkspaceEvent(mixed $page, string $workspaceId, string $event, array $payload): void
{
    $channel = json_encode("private-workspace.{$workspaceId}");
    $event = json_encode($event);
    $payload = json_encode($payload);

    waitForCondition($page, "Boolean(window.Pusher?.instances?.[0]?.channel({$channel}))");
    $page->script("window.Pusher.instances[0].channel({$channel}).emit({$event}, {$payload}); true;");
}

/**
 * A second post with one open comment in the same workspace, with the
 * reorder hint already seen so it does not cover the post list.
 */
function inboxSecondConversation(InboxConversation $conversation, string $subject = 'Second post'): InboxConversation
{
    $second = InboxConversation::factory()->create([
        'social_account_id' => $conversation->social_account_id,
        'workspace_id' => $conversation->workspace_id,
        'subject_text' => $subject,
    ]);
    InboxMessage::factory()->for($second, 'conversation')->create(['body' => 'Nice one']);
    RecountInboxConversation::execute($second);
    test()->user->forceFill(['inbox_reorder_hint_seen_at' => now()])->saveQuietly();
    test()->actingAs(test()->user->fresh());

    return $second;
}

/**
 * A mention conversation of the channel with one open mention.
 */
function inboxMentionThread(InboxConversation $comments): InboxMessage
{
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => $comments->social_account_id,
        'kind' => ConversationKind::Mention,
        'subject_text' => 'Shoutout to @me',
        'participant_username' => 'fan.bsky.social',
    ]);
    $mention = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => $conversation->platform_conversation_id,
        'platform_message_hash' => $conversation->platform_conversation_hash,
        'body' => 'Shoutout to @me',
        'author_username' => 'fan.bsky.social',
    ]);
    RecountInboxConversation::execute($conversation);

    return $mention;
}
