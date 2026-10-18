<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Inbox;

use App\Enums\SocialAccount\Platform;
use App\Models\InboxConversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A new direct message in a conversation. Blank text, the network's length
 * and its reply window are checked by SendInboxDirectMessage.
 */
class StoreInboxConversationMessageRequest extends FormRequest
{
    public function authorize(): Response
    {
        $conversation = $this->route('conversation');
        $workspace = $this->user()->currentWorkspace;

        if (! Platform::inboxModuleEnabled() || ! $conversation instanceof InboxConversation || $conversation->workspace_id !== $workspace?->id) {
            return Response::denyAsNotFound();
        }

        $member = Gate::inspect('createPost', $workspace);

        return $member->denied() ? $member : Gate::inspect('message', $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:25000'],
        ];
    }
}
