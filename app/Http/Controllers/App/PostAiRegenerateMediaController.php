<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Media\Source;
use App\Http\Requests\App\Ai\RegeneratePostMediaImageRequest;
use App\Jobs\Ai\RegeneratePostMediaImage;
use App\Models\Post;
use App\Support\PostStatusRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PostAiRegenerateMediaController extends Controller
{
    public function regenerate(RegeneratePostMediaImageRequest $request, Post $post, string $mediaId): JsonResponse
    {
        $this->authorize('update', $post);

        $workspace = $request->user()->currentWorkspace;

        if (PostStatusRules::blocksEditing($post)) {
            return response()->json([
                'message' => PostStatusRules::editBlockedMessage(),
                'errors' => ['instruction' => [PostStatusRules::editBlockedMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $mediaItem = collect($post->media ?? [])
            ->first(fn ($item) => data_get($item, 'id') === $mediaId);

        if (! is_array($mediaItem)) {
            return response()->json([
                'message' => __('posts.ai.image_regenerate.errors.media_not_found'),
            ], Response::HTTP_NOT_FOUND);
        }

        if (data_get($mediaItem, 'source') !== Source::Ai->value) {
            return response()->json([
                'message' => __('posts.ai.image_regenerate.errors.not_ai_media'),
                'errors' => ['instruction' => [__('posts.ai.image_regenerate.errors.not_ai_media')]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $regenerationId = $request->string('regeneration_id')->toString();

        RegeneratePostMediaImage::dispatch(
            workspaceId: $workspace->id,
            postId: $post->id,
            userId: $request->user()->id,
            mediaId: $mediaId,
            regenerationId: $regenerationId,
            instruction: $request->string('instruction')->toString(),
        );

        return response()->json([
            'regeneration_id' => $regenerationId,
            'channel' => "user.{$request->user()->id}.ai-media.{$regenerationId}",
        ], Response::HTTP_ACCEPTED);
    }
}
