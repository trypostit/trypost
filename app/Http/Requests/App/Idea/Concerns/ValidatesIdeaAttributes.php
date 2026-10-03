<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea\Concerns;

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Models\Idea;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait ValidatesIdeaAttributes
{
    /**
     * A media id is valid when a save in the workspace may use it (see
     * ResolveWorkspaceMedia). Ids already stored on the idea stay valid even
     * when their row is gone; SyncOwnedMedia keeps those items as stored.
     *
     * @param  list<string>  $storedMediaIds
     * @return array<string, mixed>
     */
    protected function ideaAttributeRules(Workspace $workspace, array $storedMediaIds = []): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:'.AiPromptRules::PROMPT_MAX_LENGTH],
            'idea_stage_id' => [
                'nullable',
                'uuid',
                Rule::exists('idea_stages', 'id')->where('workspace_id', $workspace->id),
            ],
            'media_ids' => ['sometimes', 'array', 'max:'.Idea::MAX_MEDIA],
            'media_ids.*' => [
                'uuid',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($workspace, $storedMediaIds): void {
                    if (! is_string($value) || ! Str::isUuid($value) || in_array($value, $storedMediaIds, true)) {
                        return;
                    }

                    if (ResolveWorkspaceMedia::execute($workspace, [$value])->isEmpty()) {
                        $fail('validation.exists')->translate();
                    }
                },
            ],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => [
                'uuid',
                Rule::exists('workspace_labels', 'id')
                    ->where('workspace_id', $workspace->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    protected function rejectEmptyIdea(Validator $validator, ?Idea $idea = null): void
    {
        $validator->after(function (Validator $validator) use ($idea): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $title = $this->has('title') ? $this->input('title') : $idea?->title;
            $body = $this->has('body') ? $this->input('body') : $idea?->body;
            $media = $this->has('media_ids') ? $this->input('media_ids') : ($idea?->media ?? []);

            if (blank($title) && blank($body) && blank($media)) {
                $validator->errors()->add('title', __('create.ideas.errors.empty'));
            }
        });
    }
}
