<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\PostTemplate\BuildTemplatePickerData;
use App\Http\Requests\App\PostTemplate\TemplatePickerRequest;
use App\Http\Resources\App\LibraryTemplateResource;
use App\Http\Resources\App\PostTemplateResource;
use Illuminate\Http\JsonResponse;

class PostTemplatePickerController extends Controller
{
    public function __invoke(TemplatePickerRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = BuildTemplatePickerData::execute($user->currentWorkspace, $user, $request->search());

        return response()->json([
            'library' => LibraryTemplateResource::collection($data['library'])->resolve(),
            'team' => PostTemplateResource::collection($data['team'])->resolve(),
            'personal' => PostTemplateResource::collection($data['personal'])->resolve(),
            'has_more' => $data['has_more'],
        ]);
    }
}
