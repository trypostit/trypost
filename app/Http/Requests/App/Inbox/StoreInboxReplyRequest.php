<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Inbox;

use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxMessage;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class StoreInboxReplyRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $message = $this->route('message');

        if (! Platform::inboxModuleEnabled() || $message->conversation->workspace_id !== $this->user()->currentWorkspace?->id) {
            return Response::denyAsNotFound();
        }

        $workspaceAccess = Gate::inspect('createPost', $this->user()->currentWorkspace);

        if ($workspaceAccess->denied()) {
            return $workspaceAccess;
        }

        return Gate::inspect('reply', $message);
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $message = $this->route('message');
            $text = $this->input('text');

            if (! $message instanceof InboxMessage || ! is_string($text) || $validator->errors()->has('text')) {
                return;
            }

            $text = Str::trim($text);
            $account = $message->socialAccount;
            $driver = app(InboxDrivers::class)->for($account->platform);
            $max = $driver?->replyLength($account) ?? $account->maxContentLength();
            $length = $driver?->replyLengthUnit()->measure($text) ?? mb_strlen($text);

            if ($text === '') {
                $validator->errors()->add('text', __('inbox.errors.empty_reply'));
            } elseif ($length > $max) {
                $validator->errors()->add('text', ($driver?->replyLengthUnit() ?? ReplyLengthUnit::Characters)->tooLongMessage($max));
            }
        });
    }
}
