<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Support\Repurpose\RepurposeRules;
use App\Support\Repurpose\SourceIsFree;
use App\Support\Repurpose\SourceIsNotADestination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRepurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function workspaceId(): ?string
    {
        return $this->user()->currentWorkspace?->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...RepurposeRules::settings($this->workspaceId(), sourceRequired: true),
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

            $sourceAccountId = $this->input('source_social_account_id');

            SourceIsFree::addErrors(
                $validator,
                $this->workspaceId(),
                $sourceAccountId,
                SourceFormat::from($this->input('source_format', SourceFormat::Reel->value)),
                null,
            );

            SourceIsNotADestination::addErrors(
                $validator,
                (array) $this->input('destinations', []),
                $sourceAccountId,
            );
        });
    }
}
