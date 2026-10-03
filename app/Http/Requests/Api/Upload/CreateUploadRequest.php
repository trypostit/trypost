<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Upload;

use App\Http\Requests\Api\StoreUploadRequest;

/**
 * `POST /api/uploads`: the file rules of the signed upload URL (type
 * allow-list, per-type size cap, HEIC) for a caller holding an API key, who
 * must be allowed to create posts before the file is looked at.
 */
class CreateUploadRequest extends StoreUploadRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createPost', $this->user()->currentWorkspace);
    }
}
