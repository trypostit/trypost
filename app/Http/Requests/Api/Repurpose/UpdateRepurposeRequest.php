<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Models\Repurpose;
use App\Support\Repurpose\DestinationMetaRules;
use App\Support\Repurpose\RepurposeRules;
use App\Support\Repurpose\SourceIsFree;
use App\Support\Repurpose\SourceIsNotADestination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRepurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function workspaceId(): ?string
    {
        return $this->user()->currentWorkspace?->id;
    }

    private function repurpose(): Repurpose
    {
        return $this->route('repurpose');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...RepurposeRules::settings($this->workspaceId(), sourceRequired: false),
            ...RepurposeRules::destinations($this->workspaceId()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return RepurposeRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return RepurposeRules::attributes();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $sourceAccountId = $this->input('source_social_account_id', $this->repurpose()->source_social_account_id);

            SourceIsFree::addErrors(
                $validator,
                $this->workspaceId(),
                $sourceAccountId,
                SourceFormat::from($this->input('source_format', $this->repurpose()->source_format->value)),
                $this->repurpose()->id,
            );

            SourceIsNotADestination::addErrors(
                $validator,
                (array) $this->input('destinations', []),
                $sourceAccountId,
            );

            if (! DestinationMetaRules::enforcedFor($this->repurpose())) {
                return;
            }

            DestinationMetaRules::addRequiredErrors(
                $validator,
                (array) $this->input('destinations', []),
                $this->workspaceId(),
            );
        });
    }
}
