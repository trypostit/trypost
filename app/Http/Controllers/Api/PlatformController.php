<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\PlatformContentTypesResource;
use Illuminate\Http\JsonResponse;

class PlatformController extends Controller
{
    /**
     * Index of platforms with their valid content_types and publishing
     * constraints — used by clients to build correct `content_type`
     * payloads for `POST /api/posts` and `PUT /api/posts/{id}`.
     */
    public function contentTypes(): JsonResponse
    {
        return response()->json([
            'platforms' => PlatformContentTypesResource::collection(Platform::cases())->resolve(),
        ]);
    }
}
