<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

test('a batch creates one independent post and target per selected account in input order', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = collect([
        SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]),
        SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]),
        SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'is_active' => true]),
        SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'is_active' => true]),
    ]);
    $types = [ContentType::InstagramFeed, ContentType::InstagramFeed, ContentType::XPost, ContentType::LinkedInPost];

    $posts = CreatePosts::execute($workspace, $user, [
        'status' => 'draft',
        'content' => 'Legenda base',
        'destinations' => $accounts->map(fn (SocialAccount $account, int $index): array => [
            'social_account_id' => $account->id,
            'content_type' => $types[$index]->value,
            'content' => "Legenda {$index}",
        ])->all(),
    ]);

    expect($posts)->toHaveCount(4)
        ->and(Post::query()->where('workspace_id', $workspace->id)->count())->toBe(4);

    foreach ($posts as $index => $post) {
        expect($post->content)->toBe("Legenda {$index}")
            ->and($post->status)->toBe(PostStatus::Draft)
            ->and($post->postPlatforms()->count())->toBe(1)
            ->and($post->postPlatforms()->enabled()->count())->toBe(1)
            ->and($post->postPlatforms()->first()->social_account_id)->toBe($accounts[$index]->id);
    }

    $posts[0]->update(['content' => 'Só este Instagram']);
    expect($posts[1]->fresh()->content)->toBe('Legenda 1');
});

test('scheduled posts copy the shared schedule and labels to each independent row', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $firstAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $secondAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $scheduledAt = now()->addDays(2)->startOfMinute();

    $posts = CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'content' => 'Texto comum',
        'scheduled_at' => $scheduledAt->toIso8601String(),
        'label_ids' => [$label->id],
        'created_via' => CreatedVia::Api,
        'destinations' => [
            ['social_account_id' => $firstAccount->id, 'content_type' => ContentType::XPost->value],
            ['social_account_id' => $secondAccount->id, 'content_type' => ContentType::LinkedInPost->value],
        ],
    ]);

    foreach ($posts as $post) {
        expect($post->status)->toBe(PostStatus::Scheduled)
            ->and($post->scheduled_at->equalTo($scheduledAt))->toBeTrue()
            ->and($post->created_via)->toBe(CreatedVia::Api)
            ->and($post->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id]);
    }
});

test('an invalid destination leaves no posts or publication jobs', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $foreignWorkspace = Workspace::factory()->create();
    $ownAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $foreignAccount = SocialAccount::factory()->x()->create(['workspace_id' => $foreignWorkspace->id, 'is_active' => true]);
    Queue::fake();

    expect(fn () => CreatePosts::execute($workspace, $user, [
        'status' => 'publishing',
        'content' => 'Texto',
        'destinations' => [
            ['social_account_id' => $ownAccount->id, 'content_type' => ContentType::XPost->value],
            ['social_account_id' => $foreignAccount->id, 'content_type' => ContentType::XPost->value],
        ],
    ]))->toThrow(ValidationException::class);

    expect(Post::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
    Queue::assertNotPushed(PublishPost::class);
});

test('publishing immediately queues each independent post', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $firstAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $secondAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    Queue::fake();

    $posts = CreatePosts::execute($workspace, $user, [
        'status' => 'publishing',
        'content' => 'Publicar agora',
        'destinations' => [
            ['social_account_id' => $firstAccount->id, 'content_type' => ContentType::XPost->value],
            ['social_account_id' => $secondAccount->id, 'content_type' => ContentType::LinkedInPost->value],
        ],
    ]);

    expect($posts->pluck('status')->all())->toBe([PostStatus::Publishing, PostStatus::Publishing]);
    Queue::assertPushed(PublishPost::class, 2);
    Queue::assertPushed(PublishPost::class, fn (PublishPost $job): bool => $job->post->id === $posts[0]->id);
    Queue::assertPushed(PublishPost::class, fn (PublishPost $job): bool => $job->post->id === $posts[1]->id);
});
