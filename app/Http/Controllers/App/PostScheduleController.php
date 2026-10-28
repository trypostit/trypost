<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\ReschedulePost;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status as PostStatus;
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

        if ($request->validated('action') === 'publish_now' && ! PostStatusRules::blocksEditing($post)) {
            PostStatusRules::assertStoredPostPublishable($post);
        }

        $result = $request->validated('action') === 'reschedule'
            ? ReschedulePost::execute($workspace, $post, $request->validated('scheduled_at'), $request->user())
            : UpdatePost::execute($workspace, $post, match ($request->validated('action')) {
                'draft' => ['status' => PostStatus::Draft->value],
                'publish_now' => ['status' => PostStatus::Publishing->value],
                'queue_next' => ['status' => PostStatus::Scheduled->value, 'queue' => QueuePosition::Next->value],
                'queue_top' => ['status' => PostStatus::Scheduled->value, 'queue' => QueuePosition::Top->value],
            }, $request->user());

        $action = data_get($result, 'action');

        if ($action === PostAction::Finalized) {
            session()->flash('flash.banner', __('posts.flash.cannot_edit_finalized'));
            session()->flash('flash.bannerStyle', 'danger');
        }

        if ($request->validated('action') !== 'reschedule' && ($action === PostAction::Publishing || $action === PostAction::Scheduled)) {
            $queue = $this->publishPageReturnUrl(['tab' => 'queue']);

            return $queue ? redirect($queue) : back();
        }

        return back();
    }
}
