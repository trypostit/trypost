<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

it('denies when the mcp request has no authenticated user', function () {
    $tool = new class
    {
        use AuthorizesMcpTool;

        public function probe(Request $request, Workspace $workspace): Response|ResponseFactory|null
        {
            return $this->denyUnlessCan($request, 'view', $workspace, 'Not authorized.');
        }
    };

    $workspace = Workspace::factory()->create();
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('user')->once()->andReturn(null);

    $denied = $tool->probe($request, $workspace);

    expect($denied)->not->toBeNull();
});

it('denies when the policy argument is null', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);
    $owner->update(['current_workspace_id' => $workspace->id]);

    $tool = new class
    {
        use AuthorizesMcpTool;

        public function probe(Request $request): Response|ResponseFactory|null
        {
            return $this->denyUnlessCan($request, 'createPost', null, 'Not authorized to create posts.');
        }
    };

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('user')->once()->andReturn($owner->fresh());

    expect($tool->probe($request))->not->toBeNull();
});

it('authorizeCurrentWorkspace fails closed without a user', function () {
    $tool = new class
    {
        use AuthorizesMcpTool;

        public function probe(Request $request): Workspace|Response|ResponseFactory
        {
            return $this->authorizeCurrentWorkspace($request, 'createPost', 'Not authorized to create posts.');
        }
    };

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('user')->twice()->andReturn(null);

    expect($tool->probe($request))->not->toBeInstanceOf(Workspace::class);
});

it('denies when the user lacks the ability', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);

    $viewer = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $workspace->id]);

    $tool = new class
    {
        use AuthorizesMcpTool;

        public function probe(Request $request, Workspace $workspace): Response|ResponseFactory|null
        {
            return $this->denyUnlessCan($request, 'createPost', $workspace, 'Not authorized to create posts.');
        }
    };

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('user')->once()->andReturn($viewer->fresh());

    expect($tool->probe($request, $workspace))->not->toBeNull();
});

it('allows when the user has the ability', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);
    $owner->update(['current_workspace_id' => $workspace->id]);

    $tool = new class
    {
        use AuthorizesMcpTool;

        public function probe(Request $request, Workspace $workspace): Response|ResponseFactory|null
        {
            return $this->denyUnlessCan($request, 'createPost', $workspace, 'Not authorized to create posts.');
        }
    };

    $request = Mockery::mock(Request::class);
    $request->shouldReceive('user')->once()->andReturn($owner->fresh());

    expect($tool->probe($request, $workspace))->toBeNull();
});
