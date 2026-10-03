<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Ai\GenerateMediaAltText;
use App\Actions\Media\ResolveWorkspaceMedia;
use App\Http\Requests\App\Ai\GenerateMediaAltTextRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class MediaAltTextController extends Controller
{
    public function __invoke(GenerateMediaAltTextRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $asset = ResolveWorkspaceMedia::execute($workspace, [$request->string('media_id')->toString()])->first();
        abort_if($asset === null, Response::HTTP_NOT_FOUND);
        abort_unless($asset->isImage(), Response::HTTP_UNPROCESSABLE_ENTITY, __('posts.composer.media_editor.alt_generate_image_only'));

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        return response()->json([
            'alt_text' => GenerateMediaAltText::execute($request->user(), $asset),
        ]);
    }
}
