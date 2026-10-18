<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreInboxChannelSyncRequest extends FormRequest
{
    public function authorize(): Response
    {
        $account = $this->route('socialAccount');
        $workspace = $this->user()->currentWorkspace;

        if (! Platform::inboxModuleEnabled() || ! $account instanceof SocialAccount || $account->workspace_id !== $workspace?->id) {
            return Response::denyAsNotFound();
        }

        return Gate::inspect('publishDirectly', $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(array_map(
                fn (ConversationKind $kind): string => $kind->value,
                app(InboxDrivers::class)->kindsFor($this->route('socialAccount')),
            ))],
        ];
    }

    public function kind(): ConversationKind
    {
        return ConversationKind::from($this->validated('kind'));
    }
}
