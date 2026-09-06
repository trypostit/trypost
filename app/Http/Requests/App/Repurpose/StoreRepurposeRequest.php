<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Models\Repurpose;
use App\Services\Repurpose\SourceFetcherFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRepurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Repurpose::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_social_account_id' => [
                'required',
                'string',
                'uuid',
                Rule::exists('social_accounts', 'id')
                    ->where('workspace_id', $this->user()->current_workspace_id)
                    ->where('is_active', true)
                    ->whereIn('platform', array_map(
                        fn (Platform $platform): string => $platform->value,
                        SourceFetcherFactory::supportedPlatforms(),
                    )),
            ],
            'source_format' => ['sometimes', Rule::enum(SourceFormat::class)],
        ];
    }
}
