<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeMatchesPlatform;
use App\Support\PostMediaRules;
use App\Support\PostPlatformMetaRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workspaceId = $this->user()->currentWorkspace->id;

        return [
            'content' => [
                'nullable',
                'string',
                'max:10000',
                Rule::when(
                    $this->filled('scheduled_at'),
                    [new ContentFitsPlatformLimits($this->resolveSelectedPlatforms($workspaceId))]
                ),
            ],
            ...PostMediaRules::rules(hosted: false),
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*.social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $workspaceId)
                    ->where('is_active', true),
            ],
            'platforms.*.content_type' => [
                'required',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                new ContentTypeMatchesPlatform,
            ],
            ...PostPlatformMetaRules::rules(),
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => [
                'uuid',
                Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspaceId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PostPlatformMetaRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return PostPlatformMetaRules::attributes();
    }

    /**
     * @return Collection<int, Platform>
     */
    public function selectedPlatforms(): Collection
    {
        return $this->resolveSelectedPlatforms($this->user()->currentWorkspace->id)->values();
    }

    /**
     * @return Collection<int|string, Platform>
     */
    private function resolveSelectedPlatforms(string $workspaceId): Collection
    {
        $accountIds = collect($this->input('platforms', []))->pluck('social_account_id')->filter()->all();
        if (empty($accountIds)) {
            return collect();
        }

        return SocialAccount::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $accountIds)
            ->pluck('platform', 'id');
    }
}
