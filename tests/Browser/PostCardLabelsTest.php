<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\PostingSchedule;

function waitForCardLabelsCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForCardLabelsTestId(mixed $page, string $testId): void
{
    waitForCardLabelsCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

function waitForCardLabelsMissing(mixed $page, string $testId): void
{
    waitForCardLabelsCondition($page, "!document.querySelector('[data-testid=\"{$testId}\"]')");
}

/**
 * @param  list<string>  $expected
 */
function waitForCardLabelsStored(mixed $page, Post $post, array $expected): void
{
    sort($expected);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        if ($post->labels()->pluck('workspace_labels.id')->sort()->values()->all() === $expected) {
            return;
        }

        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount}
 */
function cardLabelsSetup(string $role = 'admin'): array
{
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create([
        'user_id' => $owner->id,
        'account_id' => $owner->account_id,
    ]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($owner->account);

    $user = $owner;

    if ($role !== 'admin') {
        $user = User::factory()->create(['timezone' => 'UTC', 'account_id' => $owner->account_id]);
        $workspace->members()->attach($user->id, membershipPivot($role));
        $user->update(['current_workspace_id' => $workspace->id]);
    }

    $time = now()->utc()->addHours(6)->format('H:00');
    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, $time);
    }

    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => $schedule,
    ]);

    return [$user, $workspace, $channel];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function cardLabelsPost(User $user, Workspace $workspace, SocialAccount $channel, array $overrides = []): Post
{
    return CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Card labels post',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
        ...$overrides,
    ])->first();
}

function openCardLabelsPicker(mixed $page, string $key): void
{
    waitForCardLabelsTestId($page, "post-labels-{$key}-trigger");
    $page->click("@post-labels-{$key}-trigger");
    waitForCardLabelsTestId($page, "post-labels-{$key}-search");
}

test('a queued card assigns and unassigns a label from its picker', function () {
    [$user, $workspace, $channel] = cardLabelsSetup();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);
    $other = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Launch']);
    $post = cardLabelsPost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openCardLabelsPicker($page, $post->id);

    $page->assertVisible("@post-labels-{$post->id}-option-{$label->id}")
        ->assertVisible("@post-labels-{$post->id}-option-{$other->id}")
        ->assertMissing("@post-labels-{$post->id}-untagged")
        ->assertScript("document.querySelector('[data-testid=\"post-labels-{$post->id}-settings\"]').getAttribute('href')", route('app.labels.index', absolute: false));

    $page->type("@post-labels-{$post->id}-search", 'camp');
    waitForCardLabelsMissing($page, "post-labels-{$post->id}-option-{$other->id}");
    $page->assertMissing("@post-labels-{$post->id}-option-{$other->id}")
        ->click("@post-labels-{$post->id}-checkbox-{$label->id}");
    waitForCardLabelsTestId($page, "post-label-chip-{$post->id}-{$label->id}");
    waitForCardLabelsStored($page, $post, [$label->id]);

    expect($post->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id]);
    $page->assertVisible("@post-label-chip-{$post->id}-{$label->id}");

    $page->click("@post-labels-{$post->id}-checkbox-{$label->id}");
    waitForCardLabelsMissing($page, "post-label-chip-{$post->id}-{$label->id}");
    waitForCardLabelsStored($page, $post, []);

    expect($post->labels()->count())->toBe(0);
    $page->assertMissing("@post-label-chip-{$post->id}-{$label->id}")
        ->assertNoJavaScriptErrors();
});

test('a draft card clears every label with clear all', function () {
    [$user, $workspace, $channel] = cardLabelsSetup();
    $first = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);
    $second = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Launch']);
    $draft = cardLabelsPost($user, $workspace, $channel, ['status' => 'draft', 'queue' => null]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    openCardLabelsPicker($page, $draft->id);

    $page->click("@post-labels-{$draft->id}-checkbox-{$first->id}");
    waitForCardLabelsStored($page, $draft, [$first->id]);
    $page->click("@post-labels-{$draft->id}-checkbox-{$second->id}");
    waitForCardLabelsTestId($page, "post-label-chip-{$draft->id}-{$second->id}");
    waitForCardLabelsStored($page, $draft, [$first->id, $second->id]);

    expect($draft->labels()->count())->toBe(2);
    $page->assertVisible("@post-label-chip-{$draft->id}-{$first->id}")
        ->assertVisible("@post-label-chip-{$draft->id}-{$second->id}")
        ->click("@post-labels-{$draft->id}-clear");
    waitForCardLabelsMissing($page, "post-label-chip-{$draft->id}-{$first->id}");
    waitForCardLabelsStored($page, $draft, []);

    expect($draft->labels()->count())->toBe(0);
    $page->assertMissing("@post-label-chip-{$draft->id}-{$second->id}")
        ->assertVisible("@post-labels-{$draft->id}-trigger")
        ->assertNoJavaScriptErrors();
});

test('a sent card accepts labels', function () {
    [$user, $workspace, $channel] = cardLabelsSetup();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);
    $published = Post::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'published_at' => now()->subHour(),
    ]);
    PostPlatform::factory()->published()->create([
        'post_id' => $published->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    openCardLabelsPicker($page, $published->id);

    $page->click("@post-labels-{$published->id}-checkbox-{$label->id}");
    waitForCardLabelsTestId($page, "post-label-chip-{$published->id}-{$label->id}");
    waitForCardLabelsStored($page, $published, [$label->id]);

    expect($published->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id]);
    $page->assertVisible("@post-label-chip-{$published->id}-{$label->id}")
        ->assertNoJavaScriptErrors();
});

test('the label filter drops a card once its label is removed', function () {
    [$user, $workspace, $channel] = cardLabelsSetup();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);
    $tagged = cardLabelsPost($user, $workspace, $channel, ['status' => 'draft', 'queue' => null, 'label_ids' => [$label->id]]);
    $untagged = cardLabelsPost($user, $workspace, $channel, ['status' => 'draft', 'queue' => null, 'content' => 'Untagged draft']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts', 'labels' => [$label->id]]));
    waitForCardLabelsTestId($page, "post-label-chip-{$tagged->id}-{$label->id}");

    $page->assertVisible("@post-card-{$tagged->id}")
        ->assertMissing("@post-card-{$untagged->id}")
        ->assertSeeIn('@posts-label-count', '1');

    openCardLabelsPicker($page, $tagged->id);
    $page->click("@post-labels-{$tagged->id}-checkbox-{$label->id}");
    waitForCardLabelsMissing($page, "post-card-{$tagged->id}");
    waitForCardLabelsStored($page, $tagged, []);

    expect($tagged->labels()->count())->toBe(0);
    $page->assertMissing("@post-card-{$tagged->id}")
        ->assertMissing("@post-card-{$untagged->id}")
        ->assertSeeIn('@posts-label-count', '1')
        ->assertNoJavaScriptErrors();
});

test('a member who needs approval can edit the labels on a card', function () {
    [$requester, $workspace, $channel] = cardLabelsSetup('approval');
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);
    $owner = User::query()->findOrFail($workspace->user_id);
    $tagged = cardLabelsPost($owner, $workspace, $channel, ['label_ids' => [$label->id]]);
    $this->actingAs($requester);

    $page = visit(route('app.posts.index'));
    waitForCardLabelsTestId($page, "post-label-chip-{$tagged->id}-{$label->id}");

    $page->assertVisible("@post-label-chip-{$tagged->id}-{$label->id}")
        ->assertVisible("@post-labels-{$tagged->id}-trigger")
        ->assertNoJavaScriptErrors();
});

test('a rejected change rolls the card back and shows an error', function () {
    [$user, $workspace, $channel] = cardLabelsSetup();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);
    $post = cardLabelsPost($user, $workspace, $channel, ['status' => 'draft', 'queue' => null]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    openCardLabelsPicker($page, $post->id);
    $label->delete();

    $page->click("@post-labels-{$post->id}-checkbox-{$label->id}");
    waitForCardLabelsTestId($page, 'post-labels-error-toast');
    waitForCardLabelsMissing($page, "post-label-chip-{$post->id}-{$label->id}");

    expect($post->labels()->count())->toBe(0);
    $page->assertSeeIn('@post-labels-error-toast', __('posts.publish.actions.edit_labels_failed'))
        ->assertMissing("@post-label-chip-{$post->id}-{$label->id}");
});
