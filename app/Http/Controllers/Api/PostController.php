<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Post\AppendPostMedia;
use App\Actions\Post\BuildPublishPageProps;
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
use App\Http\Requests\Api\Post\ListPostsRequest;
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
use App\Support\PostCompositionValidator;
use App\Support\PostStatusRules;
use App\Support\Requests\Post\PostMediaRequestRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PostController extends Controller
{
    public function index(ListPostsRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $filters = $request->filters();

        $posts = $workspace->posts()
            ->visiblePendingApprovalsFor(BuildPublishPageProps::pendingApprovalsRequester($user, $workspace))
            ->onChannels(data_get($filters, 'channels') ?: null)
            ->matchingLabelFilter(data_get($filters, 'labels'), data_get($filters, 'untagged'))
            ->with(['socialAccount', 'user', 'approvalRequestedBy', 'approver', 'labels'])
            ->latestScheduledFirst()
            ->paginate((int) config('app.pagination.default'));

        return PostResource::collection($posts);
    }

    public function show(Request $request, Post $post): PostResource
    {
        $this->authorize('view', $post);

        $user = $request->user();
        $visible = Post::query()
            ->visiblePendingApprovalsFor(BuildPublishPageProps::pendingApprovalsRequester($user, $user->currentWorkspace))
            ->whereKey($post->getKey())
            ->exists();
        abort_unless($visible, Response::HTTP_NOT_FOUND);

        $post->load(['socialAccount', 'user', 'labels']);

        return new PostResource($post);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $data = $request->validated();

        $data = HostInlineMedia::forPost($workspace, Post::allowedMediaTypesFor($request->selectedPlatforms()), $data);

        $post = PostCompositionValidator::forSinglePost(fn (): Post => CreatePosts::execute($workspace, $request->user(), [
            'status' => $data['status'] ?? 'draft',
            'content' => $data['content'] ?? '',
            'media' => $data['media'] ?? [],
            'scheduled_at' => $data['scheduled_at'] ?? $data['queue_slot'] ?? null,
            'queue' => $data['queue'] ?? null,
            'queue_slot' => $data['queue_slot'] ?? null,
            'label_ids' => $data['label_ids'] ?? [],
            'created_via' => CreatedVia::Api,
            'destinations' => [Arr::only($data, ['social_account_id', 'content_type', 'meta'])],
        ])->sole());

        $post->load(['socialAccount', 'labels']);

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
                $post->load(['socialAccount', 'labels']);

                return (new PostResource($post))->resolve();
            })->all(),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePostRequest $request, Post $post): PostResource|JsonResponse
    {
        $this->authorize('update', $post);

        $data = $request->validated();

        $data = HostInlineMedia::forPost($request->user()->currentWorkspace, $post->allowedMediaTypes(), $data);

        $result = UpdatePost::execute($request->user()->currentWorkspace, $post, $data, $request->user());

        if (data_get($result, 'action') === PostAction::Finalized) {
            return response()->json(
                ['message' => PostStatusRules::editBlockedMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $updated = data_get($result, 'post');
        $updated->load(['socialAccount', 'labels']);

        return new PostResource($updated);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        DeletePost::execute($post, respectStatus: true);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function storeMedia(StoreMediaRequest $request, Post $post): PostResource
    {
        $file = $request->file('media');

        if ($violation = PostMediaRequestRules::typeViolation($post, MediaType::fromMime((string) $file->getMimeType()))) {
            throw ValidationException::withMessages(['media' => $violation]);
        }

        $media = $post->workspace->addMedia($file, Media::COLLECTION_UPLOADS);

        AppendPostMedia::execute($post, [MediaItem::fromMedia($media)->toArray()], $request->user());

        $post->refresh()->load(['socialAccount', 'labels']);

        return new PostResource($post);
    }

    public function attachMediaFromUpload(AttachMediaFromUploadRequest $request, Post $post): PostResource
    {
        $media = $request->upload();

        if ($violation = PostMediaRequestRules::typeViolation($post, $media->type)) {
            throw ValidationException::withMessages(['upload_token' => $violation]);
        }

        AppendPostMedia::execute($post, [MediaItem::fromMedia($media, $request->validated('alt'))->toArray()], $request->user());

        $post->refresh()->load(['socialAccount', 'labels']);

        return new PostResource($post);
    }

    public function attachMediaFromUrl(AttachMediaFromUrlRequest $request, Post $post): PostMediaAttachResource
    {
        $result = app(MediaAttacher::class)->attachFromUrls($post, $request->validated('urls'), $request->user());

        $post->refresh()->load(['socialAccount', 'labels']);

        return new PostMediaAttachResource($post, $result);
    }

    public function metrics(Request $request, Post $post): PostMetricsResource
    {
        $this->authorize('view', $post);

        $post->load(['socialAccount']);

        return new PostMetricsResource($post);
    }

    public function preview(Request $request, Post $post): PostPreviewResource
    {
        $this->authorize('view', $post);

        $post->load(['socialAccount']);

        return new PostPreviewResource($post);
    }
}
