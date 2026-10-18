<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\SavedReply;

use App\Enums\SocialAccount\Platform;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreSavedReplyRequest extends FormRequest
{
    public function authorize(): Response
    {
        if (! Platform::inboxModuleEnabled()) {
            return Response::denyAsNotFound();
        }

        return Gate::inspect('publishDirectly', $this->user()->currentWorkspace);
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
