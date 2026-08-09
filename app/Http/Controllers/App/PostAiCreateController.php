<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Ai\DraftStatus;
use App\Enums\PostPlatform\ContentType;
use App\Http\Requests\App\Ai\GenerateFromDraftRequest;
use App\Http\Requests\App\Ai\StartPostCreationRequest;
use App\Jobs\Ai\PreparePostContent;
use App\Jobs\Ai\StreamPostCreation;
use App\Models\AiPostDraft;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class PostAiCreateController extends Controller
{
    public function start(StartPostCreationRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $socialAccountId = $request->input('social_account_id');

        if ($socialAccountId) {
            $owned = SocialAccount::where('id', $socialAccountId)
                ->where('workspace_id', $workspace->id)
                ->exists();

            if (! $owned) {
                abort(Response::HTTP_FORBIDDEN);
            }
        }

        $creationId = (string) Str::uuid();

        StreamPostCreation::dispatch(
            userId: $request->user()->id,
            creationId: $creationId,
            workspaceId: $workspace->id,
            format: $request->string('format')->toString(),
            socialAccountId: $socialAccountId,
            imageCount: (int) $request->input('image_count', 0),
            prompt: $request->string('prompt')->toString(),
            date: $request->input('date'),
            template: $request->input('template', 'image_card'),
            applyBrandVisuals: $request->boolean('apply_brand_visuals', true),
        );

        return response()->json([
            'creation_id' => $creationId,
            'channel' => "user.{$request->user()->id}.ai-creation.{$creationId}",
        ], Response::HTTP_ACCEPTED);
    }

    public function loading(Request $request, string $creationId): InertiaResponse
    {
        return Inertia::render('posts/ai/Loading', [
            'creationId' => $creationId,
            'channel' => "user.{$request->user()->id}.ai-creation.{$creationId}",
            'imageCount' => (int) $request->query('images', '0'),
            'format' => (string) $request->query('format', ''),
            'prompt' => (string) $request->query('prompt', ''),
        ]);
    }

    /**
     * Phase A of the review flow: create a draft and pre-generate only its text
     * (no images) for the user to review. Returns the draft id and its channel.
     */
    public function prepare(StartPostCreationRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $socialAccountId = $request->input('social_account_id');

        if ($socialAccountId) {
            $owned = SocialAccount::where('id', $socialAccountId)
                ->where('workspace_id', $workspace->id)
                ->exists();

            if (! $owned) {
                abort(Response::HTTP_FORBIDDEN);
            }
        }

        $draft = AiPostDraft::create([
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'social_account_id' => $socialAccountId,
            'format' => $request->string('format')->toString(),
            'template' => $request->input('template', 'image_card'),
            'image_count' => (int) $request->input('image_count', 0),
            'apply_brand_visuals' => $request->boolean('apply_brand_visuals', true),
            'scheduled_date' => $request->input('date'),
            'prompt' => $request->string('prompt')->toString(),
            'status' => DraftStatus::Preparing,
        ]);

        PreparePostContent::dispatch($draft->id);

        return response()->json([
            'draft_id' => $draft->id,
            'channel' => "user.{$request->user()->id}.ai-draft.{$draft->id}",
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * The review screen: editable blocks of the pre-generated structure. While
     * the draft is still `preparing`, the page shows a skeleton and fills in on
     * the `.ai.content.prepared` broadcast.
     */
    public function review(Request $request, AiPostDraft $draft): InertiaResponse
    {
        $this->authorizeDraft($request, $draft);

        return Inertia::render('posts/ai/Review', [
            'draft' => [
                'id' => $draft->id,
                'status' => $draft->status->value,
                'format' => $draft->format,
                'template' => $draft->template,
                'is_carousel' => $draft->format === ContentType::CAROUSEL_FORMAT,
                'image_count' => $draft->image_count,
                // Formats that publish no caption (stories and the like) hide the
                // field instead of letting the user edit text that never ships.
                'supports_caption' => $draft->format === ContentType::CAROUSEL_FORMAT
                    || (ContentType::tryFrom((string) $draft->format)?->supportsCaption() ?? true),
                'structured' => $draft->structured,
                'error' => $draft->error,
                'channel' => "user.{$draft->user_id}.ai-draft.{$draft->id}",
            ],
        ]);
    }

    /**
     * Phase B: render the images from the REVIEWED structure and create the post.
     * Reuses StreamPostCreation (with `preparedStructured`), so the loading
     * screen and the completion event work unchanged.
     */
    public function generate(GenerateFromDraftRequest $request, AiPostDraft $draft): JsonResponse
    {
        $this->authorizeDraft($request, $draft);

        // Only a `ready` draft may generate: this stops a replay (double click or
        // a re-POST) from creating a duplicate post and billing images twice.
        abort_unless($draft->status === DraftStatus::Ready, Response::HTTP_CONFLICT);

        $workspace = $request->user()->currentWorkspace;

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $structured = $request->input('structured');

        $draft->update([
            'structured' => $structured,
            'status' => DraftStatus::Generating,
        ]);

        StreamPostCreation::dispatch(
            userId: $draft->user_id,
            creationId: $draft->id,
            workspaceId: $draft->workspace_id,
            format: $draft->format,
            socialAccountId: $draft->social_account_id,
            imageCount: $draft->image_count,
            prompt: $draft->prompt,
            date: $draft->scheduled_date?->format('Y-m-d'),
            template: $draft->template,
            applyBrandVisuals: $draft->apply_brand_visuals,
            preparedStructured: $structured,
            draftId: $draft->id,
        );

        return response()->json([
            'creation_id' => $draft->id,
            'channel' => "user.{$draft->user_id}.ai-creation.{$draft->id}",
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Autosaves the review edits, and only while the draft is `ready`. It never
     * triggers generation nor spends AI credit: it just persists the text.
     */
    public function autosave(GenerateFromDraftRequest $request, AiPostDraft $draft): JsonResponse
    {
        $this->authorizeDraft($request, $draft);

        if ($draft->status === DraftStatus::Ready) {
            $draft->update(['structured' => $request->input('structured')]);
        }

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Polling fallback for the loading screen: when the completion broadcast is
     * missed (dropped or reconnecting websocket), the front end asks here and
     * unblocks. In the review flow the creationId IS the draft id; in the
     * one-shot flow there is no record to consult, so the status is 'unknown'
     * and the channel stays the source of truth.
     */
    public function creationStatus(Request $request, string $creationId): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $draft = AiPostDraft::query()
            ->where('workspace_id', $workspace?->id)
            ->find($creationId);

        if (! $draft) {
            return response()->json(['status' => 'unknown']);
        }

        return response()->json(match ($draft->status) {
            DraftStatus::Completed => ['status' => 'done', 'post_id' => $draft->post_id],
            DraftStatus::Failed => ['status' => 'error', 'error' => $draft->error],
            default => ['status' => 'pending'],
        });
    }

    /** A draft belongs to the user who created it, inside their current workspace. */
    private function authorizeDraft(Request $request, AiPostDraft $draft): void
    {
        abort_unless(
            $draft->user_id === $request->user()->id
                && $draft->workspace_id === $request->user()->currentWorkspace?->id,
            Response::HTTP_FORBIDDEN,
        );
    }
}
