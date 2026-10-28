<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\ReschedulePost;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\ScheduleAction;
use App\Http\Controllers\App\Concerns\RendersPublishPage;
use App\Http\Requests\App\Post\UpdatePostScheduleRequest;
use App\Models\Post;
use App\Support\PostStatusRules;
use Illuminate\Http\RedirectResponse;

class PostScheduleController extends Controller
{
    use RendersPublishPage;

    public function update(UpdatePostScheduleRequest $request, Post $post): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('update', $post);

        $scheduleAction = $request->enum('action', ScheduleAction::class);

        if ($scheduleAction === ScheduleAction::PublishNow && ! PostStatusRules::blocksEditing($post)) {
            PostStatusRules::assertStoredPostPublishable($post);
        }

        $result = match ($scheduleAction) {
            ScheduleAction::Reschedule => ReschedulePost::execute($workspace, $post, $request->validated('scheduled_at'), $request->user()),
            default => UpdatePost::execute($workspace, $post, $scheduleAction->postChanges(), $request->user()),
        };

        $action = data_get($result, 'action');

        if ($action === PostAction::Finalized) {
            session()->flash('flash.banner', __('posts.flash.cannot_edit_finalized'));
            session()->flash('flash.bannerStyle', 'danger');
        }

        if ($scheduleAction !== ScheduleAction::Reschedule && in_array($action, [PostAction::Publishing, PostAction::Scheduled], true)) {
            $queue = $this->publishPageReturnUrl(['tab' => 'queue']);

            return $queue ? redirect($queue) : back();
        }

        return back();
    }
}
