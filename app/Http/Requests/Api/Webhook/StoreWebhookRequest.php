<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Webhook;

use App\Support\WebhookRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreWebhookRequest extends FormRequest
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
        return WebhookRules::store();
    }
}
