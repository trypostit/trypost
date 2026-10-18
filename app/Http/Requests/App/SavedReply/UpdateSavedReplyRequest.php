<?php

declare(strict_types=1);

namespace App\Http\Requests\App\SavedReply;

use App\Enums\SocialAccount\Platform;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSavedReplyRequest extends FormRequest
{
    public function authorize(): Response
    {
        $workspace = $this->user()->currentWorkspace;

        if (! Platform::inboxModuleEnabled() || $this->route('savedReply')->workspace_id !== $workspace?->id) {
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
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:1000'],
        ];
    }
}
