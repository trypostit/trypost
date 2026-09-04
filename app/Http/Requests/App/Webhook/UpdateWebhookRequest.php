<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Webhook;

use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['sometimes', 'url', 'max:255'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::enum(EventType::class)],
            'status' => ['sometimes', 'string', Rule::enum(Status::class)->only([Status::Enabled, Status::Disabled])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'endpoint' => __('webhooks.create.endpoint'),
            'events' => __('webhooks.create.events'),
            'events.*' => __('webhooks.create.events'),
            'status' => __('webhooks.table.status'),
        ];
    }
}
