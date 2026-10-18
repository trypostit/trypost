<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Inbox;

use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\ResolveScope;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreInboxResolutionRequest extends FormRequest
{
    private ?InboxMessage $targetMessage = null;

    private ?InboxConversation $targetConversation = null;

    /**
     * @var list<string>
     */
    private array $targetChannelIds = [];

    public function authorize(): Response
    {
        $workspace = $this->user()->currentWorkspace;

        if (! Platform::inboxModuleEnabled() || blank($workspace)) {
            return Response::denyAsNotFound();
        }

        $member = Gate::inspect('createPost', $workspace);

        if ($member->denied()) {
            return $member;
        }

        $scope = ResolveScope::tryFrom((string) $this->input('scope'));

        if ($scope?->needsMessage()) {
            if (! $this->isUuidInput('message_id')) {
                return Response::allow();
            }

            $this->targetMessage = InboxMessage::query()->with('conversation')->find($this->input('message_id'));

            if (blank($this->targetMessage) || $this->targetMessage->conversation->workspace_id !== $workspace->id) {
                return Response::denyAsNotFound();
            }

            return Gate::inspect('resolve', $this->targetMessage);
        }

        if ($scope === ResolveScope::Conversation) {
            if (! $this->isUuidInput('conversation_id')) {
                return Response::allow();
            }

            $this->targetConversation = InboxConversation::query()->find($this->input('conversation_id'));

            if (blank($this->targetConversation) || $this->targetConversation->workspace_id !== $workspace->id) {
                return Response::denyAsNotFound();
            }

            $view = Gate::inspect('view', $this->targetConversation);

            return $view->allowed() ? Gate::inspect('publishDirectly', $workspace) : $view;
        }

        if ($scope !== null && filled($this->input('channels'))) {
            $channelIds = collect((array) $this->input('channels'))->unique()->values();

            if (! $channelIds->every(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))) {
                return Response::allow();
            }

            $this->targetChannelIds = $workspace->socialAccounts()->whereIn('platform', Platform::inboxValues())->whereKey($channelIds->all())->pluck('id')->all();

            if (count($this->targetChannelIds) !== $channelIds->count()) {
                return Response::denyAsNotFound();
            }
        }

        return Gate::inspect('publishDirectly', $workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(ResolveScope::class)],
            'message_id' => ['required_if:scope,message,thread', 'nullable', 'uuid'],
            'conversation_id' => ['required_if:scope,conversation', 'nullable', 'uuid'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['uuid'],
            'tab' => ['sometimes', Rule::enum(InboxTab::class)],
        ];
    }

    private function isUuidInput(string $key): bool
    {
        $value = $this->input($key);

        return is_string($value) && Str::isUuid($value);
    }

    public function message(): ?InboxMessage
    {
        return $this->targetMessage;
    }

    public function conversation(): ?InboxConversation
    {
        return $this->targetConversation;
    }

    /**
     * @return list<string>
     */
    public function channelIds(): array
    {
        return $this->targetChannelIds;
    }

    public function tab(): InboxTab
    {
        return InboxTab::tryFrom((string) $this->validated('tab')) ?? InboxTab::DEFAULT;
    }
}
