<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Support\PostApproval;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ApprovePostRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('approve', $this->route('post'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return PostApproval::rules();
    }
}
