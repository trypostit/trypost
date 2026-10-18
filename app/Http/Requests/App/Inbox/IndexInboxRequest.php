<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Inbox;

use App\Enums\Inbox\DateRange;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\PostsFilter;
use App\Enums\Inbox\Sort;
use App\Enums\Inbox\StatusFilter;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxConversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInboxRequest extends FormRequest
{
    protected $redirectRoute = 'app.inbox.index';

    public function authorize(): Response
    {
        return Platform::inboxModuleEnabled() ? Response::allow() : Response::denyAsNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['bail', 'uuid', Rule::exists('social_accounts', 'id')->where('workspace_id', $this->user()->current_workspace_id)->whereIn('platform', Platform::inboxValues())],
            'status' => ['sometimes', Rule::enum(StatusFilter::class)],
            'sort' => ['sometimes', Rule::enum(Sort::class)],
            'range' => ['sometimes', Rule::enum(DateRange::class)],
            'tab' => ['sometimes', Rule::enum(InboxTab::class)],
            'posts' => ['sometimes', Rule::enum(PostsFilter::class)],
            'post' => ['sometimes', 'nullable', 'uuid'],
            'thread_conversation' => ['sometimes', 'nullable', 'uuid'],
            'thread_around' => ['sometimes', 'nullable', 'uuid'],
            'messages_around' => ['sometimes', 'nullable', 'bail', 'uuid', Rule::exists('inbox_messages', 'id')->where(fn (QueryBuilder $messages) => $messages->whereIn(
                'inbox_conversation_id',
                InboxConversation::query()->visibleInTab($this->user()->currentWorkspace, InboxTab::tryFrom((string) $this->input('tab')) ?? InboxTab::DEFAULT)->select('id'),
            ))],
        ];
    }
}
