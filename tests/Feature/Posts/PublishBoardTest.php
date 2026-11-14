<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'position' => 1,
    ]);
});

function boardPost(SocialAccount $channel, string $state = 'draft', array $attributes = []): Post
{
    return Post::factory()->forAccount($channel)->{$state}()->create([
        'user_id' => $channel->workspace->user_id,
        ...$attributes,
    ]);
}

/**
 * @return list<string>
 */
function boardColumnIds(Assert $page, string $key): array
{
    return collect(data_get($page->toArray(), "props.columns.{$key}.data"))->pluck('id')->all();
}

/**
 * A partial reload of the board asking for these props, as the page sends it.
 */
function boardReload(string $only, array $query = [], ?Closure $beforeReload = null): TestResponse
{
    $test = test();
    $url = route('app.posts.board', $query);
    $version = (string) data_get($test->actingAs($test->user)->get($url)->viewData('page'), 'version');

    if ($beforeReload) {
        $beforeReload();
    }

    $response = $test->withHeaders([
        Header::INERTIA => 'true',
        Header::VERSION => $version,
        Header::PARTIAL_COMPONENT => 'posts/Board',
        Header::PARTIAL_ONLY => $only,
    ])->get($url)->assertOk();

    $test->flushHeaders();

    return $response;
}

test('it renders the board on both pages', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.board'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('posts/Board')
            ->where('scope', 'all')
            ->where('channel', null)
            ->where('group', 'status')
            ->where('groups', ['status', 'tag', 'channel'])
            ->where('hasChannels', true)
            ->where('authUserId', $this->user->id));

    $this->actingAs($this->user)
        ->get(route('app.channels.board', $this->channel))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('posts/Board')
            ->where('scope', 'channel')
            ->where('channel.id', $this->channel->id)
            ->where('group', 'status')
            ->where('groups', ['status', 'tag']));
});

test('it redirects compose=1 to the board with the composer flash', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['compose' => 1, 'group' => 'tag']))
        ->assertRedirect(route('app.posts.board', ['group' => 'tag']))
        ->assertSessionHas('flash.openPostComposer');

    $this->actingAs($this->user)
        ->get(route('app.channels.board', ['account' => $this->channel, 'compose' => 1, 'group' => 'tag']))
        ->assertRedirect(route('app.channels.board', ['account' => $this->channel, 'group' => 'tag']))
        ->assertSessionHas('flash.openPostComposer');
});

test('it groups posts by status into the five columns', function () {
    $draft = boardPost($this->channel, 'draft');
    $pending = boardPost($this->channel, 'pendingApproval');
    $scheduled = boardPost($this->channel, 'scheduled');
    $publishing = boardPost($this->channel, 'publishing', ['scheduled_at' => now()->subMinute()]);
    $published = boardPost($this->channel, 'published');
    $failed = boardPost($this->channel, 'failed');

    $this->actingAs($this->user)
        ->get(route('app.posts.board'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns', [
                ['key' => 'draft', 'type' => 'status'],
                ['key' => 'approvals', 'type' => 'status'],
                ['key' => 'scheduled', 'type' => 'status'],
                ['key' => 'published', 'type' => 'status'],
                ['key' => 'failed', 'type' => 'status'],
            ])
            ->missing('columns.draft')
            ->loadDeferredProps(function (Assert $reload) use ($draft, $pending, $scheduled, $publishing, $published, $failed): void {
                $reload->where('columns.draft.total', 1)
                    ->where('columns.approvals.total', 1)
                    ->where('columns.scheduled.total', 2)
                    ->where('columns.published.total', 1)
                    ->where('columns.failed.total', 1);

                expect(boardColumnIds($reload, 'draft'))->toBe([$draft->id])
                    ->and(boardColumnIds($reload, 'approvals'))->toBe([$pending->id])
                    ->and(boardColumnIds($reload, 'scheduled'))->toBe([$publishing->id, $scheduled->id])
                    ->and(boardColumnIds($reload, 'published'))->toBe([$published->id])
                    ->and(boardColumnIds($reload, 'failed'))->toBe([$failed->id]);
            }));
});

test('it lists a post with two labels in both tag columns', function () {
    $alpha = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Alpha']);
    $beta = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Beta']);
    $both = boardPost($this->channel, 'draft');
    $both->labels()->attach([$alpha->id, $beta->id]);
    $none = boardPost($this->channel, 'published');

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'tag']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group', 'tag')
            ->where('boardColumns.0.key', "label_{$alpha->id}")
            ->where('boardColumns.0.type', 'label')
            ->where('boardColumns.0.label.name', 'Alpha')
            ->where('boardColumns.1.key', "label_{$beta->id}")
            ->where('boardColumns.2', ['key' => 'untagged', 'type' => 'untagged'])
            ->has('boardColumns', 3)
            ->loadDeferredProps(function (Assert $reload) use ($alpha, $beta, $both, $none): void {
                $reload->where("columns.label_{$alpha->id}.total", 1)
                    ->where("columns.label_{$beta->id}.total", 1)
                    ->where('columns.untagged.total', 1);

                expect(boardColumnIds($reload, "label_{$alpha->id}"))->toBe([$both->id])
                    ->and(boardColumnIds($reload, "label_{$beta->id}"))->toBe([$both->id])
                    ->and(boardColumnIds($reload, 'untagged'))->toBe([$none->id]);
            }));
});

test('it applies the label filter to a status column and its total', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $tagged = boardPost($this->channel, 'draft');
    $tagged->labels()->attach($label);
    boardPost($this->channel, 'draft');

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['labels' => [$label->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(function (Assert $reload) use ($tagged): void {
                $reload->where('columns.draft.total', 1);
                expect(boardColumnIds($reload, 'draft'))->toBe([$tagged->id]);
            }));
});

test('it limits tag columns to the label filter', function () {
    $alpha = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Alpha']);
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Beta']);

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'tag', 'labels' => [$alpha->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns', [['key' => "label_{$alpha->id}", 'type' => 'label', 'label' => ['id' => $alpha->id, 'name' => 'Alpha', 'color' => $alpha->color]]])
            ->where('filters.labels', [$alpha->id]));

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'tag', 'labels' => [$alpha->id], 'untagged' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.0.key', "label_{$alpha->id}")
            ->where('boardColumns.1.key', 'untagged')
            ->has('boardColumns', 2));
});

test('it groups by channel with drafts without a channel first', function () {
    $second = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'position' => 0,
    ]);
    $orphan = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $onFirst = boardPost($this->channel, 'scheduled');
    $onSecond = boardPost($second, 'published');

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'channel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group', 'channel')
            ->where('boardColumns.0', ['key' => 'no_channel', 'type' => 'no_channel'])
            ->where('boardColumns.1.key', "channel_{$second->id}")
            ->where('boardColumns.1.type', 'channel')
            ->where('boardColumns.1.channel.id', $second->id)
            ->where('boardColumns.2.key', "channel_{$this->channel->id}")
            ->has('boardColumns', 3)
            ->loadDeferredProps(function (Assert $reload) use ($orphan, $onFirst, $onSecond, $second): void {
                $reload->where('columns.no_channel.total', 1)
                    ->where("columns.channel_{$second->id}.total", 1)
                    ->where("columns.channel_{$this->channel->id}.total", 1);

                expect(boardColumnIds($reload, 'no_channel'))->toBe([$orphan->id])
                    ->and(boardColumnIds($reload, "channel_{$second->id}"))->toBe([$onSecond->id])
                    ->and(boardColumnIds($reload, "channel_{$this->channel->id}"))->toBe([$onFirst->id]);
            }));

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'channel', 'channels' => [$second->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.0.key', "channel_{$second->id}")
            ->has('boardColumns', 1));
});

test('it leaves out the no channel column when every post has a channel', function () {
    boardPost($this->channel, 'scheduled');

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'channel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.0.key', "channel_{$this->channel->id}")
            ->has('boardColumns', 1));
});

test('it leaves out the no channel column when its only posts are hidden by the label filter or by approval visibility', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $author = workspaceMember($this->workspace, 'approval');
    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $author->id,
        'status' => PostStatus::PendingApproval,
        'approval_requested_by' => $author->id,
    ]);
    $requester = workspaceMember($this->workspace, 'approval');

    $this->actingAs($requester)
        ->get(route('app.posts.board', ['group' => 'channel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.0.key', "channel_{$this->channel->id}")
            ->has('boardColumns', 1));

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'channel', 'labels' => [$label->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.0.key', "channel_{$this->channel->id}")
            ->has('boardColumns', 1));

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'channel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns.0.key', 'no_channel')
            ->has('boardColumns', 2));
});

test('it builds no column for a workspace without channels', function () {
    $this->channel->delete();
    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'channel']))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $page->where('hasChannels', false)
                ->where('boardColumns', [])
                ->where('columns', []);

            expect(data_get($page->toArray(), 'deferredProps'))->toBeEmpty();
        });
});

test('it shows no tag column for a label filter naming no label of the workspace', function () {
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'tag', 'labels' => [(string) str()->uuid()]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('boardColumns', []));
});

test('it falls back to status for group=channel on a channel page', function () {
    $this->actingAs($this->user)
        ->get(route('app.channels.board', ['account' => $this->channel, 'group' => 'channel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group', 'status')
            ->where('boardColumns.0.key', 'draft'));

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'unknown']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('group', 'status'));
});

test('it shows a member who needs approval only their own pending posts', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $own = boardPost($this->channel, 'pendingApproval', ['user_id' => $requester->id, 'approval_requested_by' => $requester->id]);
    $other = boardPost($this->channel, 'pendingApproval');
    $own->labels()->attach($label);
    $other->labels()->attach($label);

    $this->actingAs($requester)
        ->get(route('app.posts.board'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => expect(boardColumnIds($reload->where('columns.approvals.total', 1), 'approvals'))->toBe([$own->id])));

    $this->actingAs($requester)
        ->get(route('app.posts.board', ['group' => 'tag']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => expect(boardColumnIds($reload->where("columns.label_{$label->id}.total", 1), "label_{$label->id}"))->toBe([$own->id])));

    $this->actingAs($requester)
        ->get(route('app.posts.board', ['group' => 'channel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => expect(boardColumnIds($reload->where("columns.channel_{$this->channel->id}.total", 1), "channel_{$this->channel->id}"))->toBe([$own->id])));
});

test('it never shows another workspace\'s posts or labels', function () {
    $otherWorkspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $otherChannel = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id, 'platform' => Platform::X]);
    $otherLabel = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);
    $foreign = boardPost($otherChannel, 'draft');
    $foreign->labels()->attach($otherLabel);
    $mine = boardPost($this->channel, 'draft');

    $this->actingAs($this->user)
        ->get(route('app.posts.board'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => expect(boardColumnIds($reload->where('columns.draft.total', 1), 'draft'))->toBe([$mine->id])));

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'tag', 'labels' => [$otherLabel->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('boardColumns', [])
            ->has('labels', 0));

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['group' => 'channel', 'channels' => [$otherChannel->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('boardColumns', []));
});

test('it pages a column with its own page name', function () {
    config(['app.pagination.default' => 2]);

    $oldest = boardPost($this->channel, 'draft', ['created_at' => now()->subHours(3)]);
    boardPost($this->channel, 'draft', ['created_at' => now()->subHours(2)]);
    boardPost($this->channel, 'draft', ['created_at' => now()->subHour()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.board', ['draft_page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('columns.draft', function (Assert $reload) use ($oldest): void {
                $reload->where('columns.draft.total', 3);
                expect(boardColumnIds($reload, 'draft'))->toBe([$oldest->id]);
                $reload->missing('columns.scheduled');
            }));
});

test('it loads only the requested column on a partial reload of columns', function () {
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'position' => 2]);
    $post = boardPost($this->channel, 'draft');
    boardPost($other, 'draft');

    $response = boardReload("columns.channel_{$this->channel->id}", ['group' => 'channel'])->json('props');

    expect(collect(data_get($response, "columns.channel_{$this->channel->id}.data"))->pluck('id')->all())->toBe([$post->id])
        ->and(data_get($response, 'columns'))->toHaveKeys(["channel_{$this->channel->id}"])->toHaveCount(1)
        ->and($response)->not->toHaveKey('boardColumns');
});

test('a partial reload of a column builds no column for another workspace\'s label or channel or an unknown key', function () {
    $otherWorkspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $otherChannel = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id, 'platform' => Platform::X]);
    $otherLabel = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);
    boardPost($otherChannel, 'draft')->labels()->attach($otherLabel);

    $tag = boardReload("columns.label_{$otherLabel->id},columns.label_nope,columns.draft", ['group' => 'tag'])->json('props');
    $channel = boardReload("columns.channel_{$otherChannel->id}", ['group' => 'channel'])->json('props');

    expect(data_get($tag, 'columns', []))->toBe([])
        ->and(data_get($channel, 'columns', []))->toBe([]);
});
