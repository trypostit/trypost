<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Post\AppendPostMedia;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\DeletePost;
use App\Actions\Post\HostInlineMedia;
use App\Actions\Post\UpdatePost;
use App\Dto\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\CreatedVia;
use App\Http\Requests\Api\Post\AttachMediaFromUploadRequest;
use App\Http\Requests\Api\Post\AttachMediaFromUrlRequest;
use App\Http\Requests\Api\Post\StoreMediaRequest;
use App\Http\Requests\Api\Post\StorePostRequest;
use App\Http\Requests\Api\Post\StorePostsRequest;
use App\Http\Requests\Api\Post\UpdatePostRequest;
use App\Http\Resources\Api\PostMediaAttachResource;
use App\Http\Resources\Api\PostMetricsResource;
use App\Http\Resources\Api\PostPreviewResource;
use App\Http\Resources\Api\PostResource;
use App\Models\Media;
use App\Models\Post;
use App\Services\Post\MediaAttacher;
use App\Support\PostStatusRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PostController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $posts = $request->user()->currentWorkspace->posts()
            ->with(['postPlatforms.socialAccount', 'user', 'labels'])
            ->latest('scheduled_at')
            ->paginate((int) config('app.pagination.default'));

        return PostResource::collection($posts);
    }

    public function show(Request $request, Post $post): PostResource
    {
        $this->authorize('view', $post);

        $post->load(['postPlatforms.socialAccount', 'user', 'labels']);

        return new PostResource($post);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $data = $request->validated();

        if (array_key_exists('media', $data)) {
            $data['media'] = HostInlineMedia::execute(
                $workspace,
                Post::allowedMediaTypesFor($request->selectedPlatforms()),
                $data['media'],
            );
        }

        $post = CreatePosts::execute($workspace, $request->user(), [
            'status' => $data['status'] ?? 'draft',
            'content' => $data['content'] ?? '',
            'media' => $data['media'] ?? [],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'queue' => $data['queue'] ?? null,
            'label_ids' => $data['label_ids'] ?? [],
            'created_via' => CreatedVia::Api,
            'destinations' => [$data['platforms'][0]],
        ])->sole();

        $post->load(['postPlatforms.socialAccount']);

        return (new PostResource($post))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function storeBatch(StorePostsRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $data = HostInlineMedia::forBatch($workspace, $request->validated());

        $data['created_via'] = CreatedVia::Api;
        $posts = CreatePosts::execute($workspace, $request->user(), $data);

        return response()->json([
            'posts' => $posts->map(function (Post $post): array {
                $post->load(['postPlatforms.socialAccount', 'labels']);

                return (new PostResource($post))->resolve();
            })->all(),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePostRequest $request, Post $post): PostResource|JsonResponse
    {
        $this->authorize('update', $post);

        $data = $request->validated();

        if (array_key_exists('media', $data)) {
            $data['media'] = HostInlineMedia::execute(
                $request->user()->currentWorkspace,
                $post->allowedMediaTypes(),
                $data['media'],
            );
        }

        $result = UpdatePost::execute($request->user()->currentWorkspace, $post, $data, $request->user());

        if (data_get($result, 'action') === PostAction::Finalized) {
            return response()->json(
                ['message' => PostStatusRules::editBlockedMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $updated = data_get($result, 'post');
        $updated->load(['postPlatforms.socialAccount']);

        return new PostResource($updated);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        DeletePost::execute($post);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function storeMedia(StoreMediaRequest $request, Post $post): PostResource
    {
        $this->authorize('update', $post);

        $file = $request->file('media');
        $type = MediaType::fromMime((string) $file->getMimeType());

        if ($type === null || ! in_array($type, $post->allowedMediaTypes(), true)) {
            throw ValidationException::withMessages([
                'media' => __('posts.errors.media_type_unsupported'),
            ]);
        }

        if ($file->getSize() > $type->maxSizeInBytes()) {
            throw ValidationException::withMessages([
                'media' => 'File size exceeds the maximum allowed for this media type.',
            ]);
        }

        $media = $post->workspace->addMedia($file, Media::COLLECTION_UPLOADS);

        AppendPostMedia::execute($post, [MediaItem::fromMedia($media)->toArray()], $request->user());

        $post->refresh()->load(['postPlatforms.socialAccount', 'labels']);

        return new PostResource($post);
    }

    public function attachMediaFromUpload(AttachMediaFromUploadRequest $request, Post $post): PostResource
    {
        $this->authorize('update', $post);

        $media = $request->upload();

        if (! in_array($media->type, $post->allowedMediaTypes(), true)) {
            throw ValidationException::withMessages([
                'upload_token' => __('posts.errors.media_type_unsupported'),
            ]);
        }

        AppendPostMedia::execute($post, [MediaItem::fromMedia($media, $request->validated('alt'))->toArray()], $request->user());

        $post->refresh()->load(['postPlatforms.socialAccount', 'labels']);

        return new PostResource($post);
    }

    public function attachMediaFromUrl(AttachMediaFromUrlRequest $request, Post $post): PostMediaAttachResource
    {
        $this->authorize('update', $post);

        $result = app(MediaAttacher::class)->attachFromUrls($post, $request->validated('urls'), $request->user());

        $post->refresh()->load(['postPlatforms.socialAccount', 'labels']);

        return new PostMediaAttachResource($post, $result);
    }

    public function metrics(Request $request, Post $post): PostMetricsResource
    {
        $this->authorize('view', $post);

        $post->load(['postPlatforms.socialAccount']);

        return new PostMetricsResource($post);
    }

    public function preview(Request $request, Post $post): PostPreviewResource
    {
        $this->authorize('view', $post);

        $post->load(['postPlatforms.socialAccount']);

        return new PostPreviewResource($post);
    }
}
