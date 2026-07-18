<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Agents\PostImageRegenerator;
use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Events\Ai\PostMediaRegenerated;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Ai\RecordAiUsage;
use App\Services\Image\TemplateImageGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RegeneratePostMediaImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $workspaceId,
        public string $postId,
        public string $userId,
        public string $mediaId,
        public string $regenerationId,
        public string $instruction,
    ) {
        $this->onQueue('ai');
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('RegeneratePostMediaImage failed', [
            'post_id' => $this->postId,
            'media_id' => $this->mediaId,
            'regeneration_id' => $this->regenerationId,
            'error' => $exception?->getMessage(),
        ]);

        PostMediaRegenerated::dispatch(
            userId: $this->userId,
            regenerationId: $this->regenerationId,
            postId: $this->postId,
            media: null,
            error: __('posts.ai.image_regenerate.errors.unavailable'),
        );
    }

    public function handle(): void
    {
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $post = $this->loadPost($workspace);
        $target = $this->resolveAiMediaTarget($post);

        $baseContext = $this->buildSourceContext(
            sourceMeta: is_array(data_get($target, 'source_meta')) ? data_get($target, 'source_meta') : [],
            post: $post,
            workspace: $workspace,
        );

        $copy = $this->regenerateSlideCopy($workspace, $post, $baseContext);
        $rendered = $this->renderRegeneratedImage($workspace, $post, $copy, $baseContext);
        $newMediaItem = $this->replaceMediaOnPost($post, $target, $workspace, $rendered);

        PostMediaRegenerated::dispatch(
            userId: $this->userId,
            regenerationId: $this->regenerationId,
            postId: $post->id,
            media: $newMediaItem,
            error: null,
        );
    }

    private function loadPost(Workspace $workspace): Post
    {
        return Post::query()
            ->where('workspace_id', $workspace->id)
            ->with(['postPlatforms.socialAccount', 'workspace'])
            ->findOrFail($this->postId);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAiMediaTarget(Post $post): array
    {
        $target = collect($post->media ?? [])
            ->first(fn ($item) => data_get($item, 'id') === $this->mediaId);

        if (! is_array($target)) {
            throw new RuntimeException('Media item no longer exists in post.');
        }

        if (data_get($target, 'source') !== Source::Ai->value) {
            throw new RuntimeException('Only AI media can be regenerated.');
        }

        return $target;
    }

    /**
     * @param  array{
     *   title: string,
     *   body: string,
     *   keywords: array<int, string>,
     *   background_path: string,
     *   language: string,
     *   width: int,
     *   height: int
     * }  $baseContext
     * @return array{
     *   title: string,
     *   body: string,
     *   keywords: array<int, string>,
     *   regenerate_image: bool,
     *   regenerate_text: bool,
     *   change_mode: 'image_only'|'text_only'|'both'
     * }
     */
    private function regenerateSlideCopy(Workspace $workspace, Post $post, array $baseContext): array
    {
        /** @var PostImageRegenerator $agent */
        $agent = app(PostImageRegenerator::class, ['workspace' => $workspace]);

        $response = $agent->prompt(json_encode([
            'instruction' => $this->instruction,
            'title' => $baseContext['title'],
            'body' => $baseContext['body'],
            'keywords' => $baseContext['keywords'],
            'language' => $baseContext['language'],
        ], JSON_THROW_ON_ERROR));

        RecordAiUsage::recordText(
            workspace: $workspace,
            promptTokens: $response->usage?->promptTokens ?? 0,
            completionTokens: $response->usage?->completionTokens ?? 0,
            provider: (string) config('ai.default'),
            model: (string) config('ai.default_text_model'),
            userId: $this->userId,
            postId: $post->id,
            metadata: ['agent' => 'post_image_regenerator'],
        );

        return $this->mergeStructuredCopy($baseContext, $response->structured ?? []);
    }

    /**
     * @param  array{
     *   title: string,
     *   body: string,
     *   keywords: array<int, string>,
     *   background_path: string,
     *   language: string,
     *   width: int,
     *   height: int
     * }  $baseContext
     * @param  array<string, mixed>  $structured
     * @return array{
     *   title: string,
     *   body: string,
     *   keywords: array<int, string>,
     *   regenerate_image: bool,
     *   regenerate_text: bool,
     *   change_mode: 'image_only'|'text_only'|'both'
     * }
     */
    private function mergeStructuredCopy(array $baseContext, array $structured): array
    {
        $changeMode = $this->resolveChangeMode((string) data_get($structured, 'change_mode', 'both'));
        $regenerateImage = in_array($changeMode, ['image_only', 'both'], true);
        $regenerateText = in_array($changeMode, ['text_only', 'both'], true);
        $keywords = $this->normalizeKeywords(data_get($structured, 'keywords', $baseContext['keywords']));

        return [
            'title' => $regenerateText
                ? trim((string) data_get($structured, 'title', $baseContext['title']))
                : $baseContext['title'],
            'body' => $regenerateText
                ? trim((string) data_get($structured, 'body', $baseContext['body']))
                : $baseContext['body'],
            'keywords' => $regenerateImage && $keywords !== [] ? $keywords : $baseContext['keywords'],
            'regenerate_image' => $regenerateImage,
            'regenerate_text' => $regenerateText,
            'change_mode' => $changeMode,
        ];
    }

    /**
     * @param  array{
     *   title: string,
     *   body: string,
     *   keywords: array<int, string>,
     *   regenerate_image: bool,
     *   regenerate_text: bool,
     *   change_mode: 'image_only'|'text_only'|'both'
     * }  $copy
     * @param  array{
     *   title: string,
     *   body: string,
     *   keywords: array<int, string>,
     *   language: string,
     *   width: int,
     *   height: int
     * }  $baseContext
     * @return array{path: string, source_meta: array<string, mixed>}
     */
    private function renderRegeneratedImage(
        Workspace $workspace,
        Post $post,
        array $copy,
        array $baseContext,
    ): array {
        $socialAccount = $this->resolveSocialAccount($post, $workspace);

        if (! $socialAccount) {
            throw new RuntimeException('No social account available for image footer rendering.');
        }

        $reusedBackgroundPath = null;
        if (! $copy['regenerate_image']) {
            $reusedBackgroundPath = (string) data_get($baseContext, 'background_path', '');
            if ($reusedBackgroundPath === '') {
                $reusedBackgroundPath = null;
            }
        }

        $rendered = app(TemplateImageGenerator::class)->render(
            workspace: $workspace,
            socialAccount: $socialAccount,
            title: $copy['title'],
            body: $copy['body'],
            imageKeywords: $copy['keywords'],
            width: $baseContext['width'],
            height: $baseContext['height'],
            backgroundPath: $reusedBackgroundPath,
        );

        if (! $rendered) {
            throw new RuntimeException('Image generator failed to produce media.');
        }

        return $rendered;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array{path: string, source_meta: array<string, mixed>}  $rendered
     * @return array<string, mixed>
     */
    private function replaceMediaOnPost(
        Post $post,
        array $target,
        Workspace $workspace,
        array $rendered,
    ): array {
        $renderedPath = $rendered['path'];
        $newBackgroundPath = (string) data_get($rendered, 'source_meta.background_path', '');
        $oldBackgroundPath = (string) data_get($target, 'source_meta.background_path', '');

        try {
            $newMediaItem = DB::transaction(function () use ($post, $rendered, $target, $workspace) {
                $newMediaItem = $this->buildAiMediaItem($workspace, $rendered);

                $fresh = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();
                $items = collect($fresh->media ?? []);

                $currentIndex = $items->search(fn ($item) => data_get($item, 'id') === $this->mediaId);
                if ($currentIndex === false) {
                    throw new RuntimeException('Media item changed before regeneration completed.');
                }

                if (($meta = data_get($items->get($currentIndex), 'meta')) !== null) {
                    $newMediaItem['meta'] = $meta;
                }

                $items->put($currentIndex, $newMediaItem);
                $fresh->update(['media' => $items->values()->all()]);

                Media::query()->where('id', data_get($target, 'id'))->first()?->delete();

                return $newMediaItem;
            });

            if ($oldBackgroundPath !== '' && $oldBackgroundPath !== $newBackgroundPath && Storage::exists($oldBackgroundPath)) {
                Storage::delete($oldBackgroundPath);
            }

            return $newMediaItem;
        } catch (Throwable $exception) {
            $this->discardRenderedFile($renderedPath);
            if ($newBackgroundPath !== '' && $newBackgroundPath !== $oldBackgroundPath && Storage::exists($newBackgroundPath)) {
                Storage::delete($newBackgroundPath);
            }

            throw $exception;
        }
    }

    private function discardRenderedFile(string $path): void
    {
        if ($path !== '' && Storage::exists($path)) {
            Storage::delete($path);
        }
    }

    /**
     * @param  array<string, mixed>  $sourceMeta
     * @return array{
     *   title: string,
     *   body: string,
     *   keywords: array<int, string>,
     *   background_path: string,
     *   language: string,
     *   width: int,
     *   height: int
     * }
     */
    private function buildSourceContext(array $sourceMeta, Post $post, Workspace $workspace): array
    {
        $title = trim((string) data_get($sourceMeta, 'title', ''));
        $body = trim((string) data_get($sourceMeta, 'body', ''));
        $keywords = $this->normalizeKeywords(data_get($sourceMeta, 'keywords', []));

        if ($title === '' && $body === '') {
            [$title, $body] = $this->titleAndBodyFromPostContent($post);
        }

        if ($title === '') {
            $title = __('posts.ai.image_regenerate.fallback_title');
        }

        if ($keywords === []) {
            $keywords = $this->keywordsFromCopy($title, $body);
        }

        if ($keywords === []) {
            $keywords = ['social media', 'marketing'];
        }

        return [
            'title' => $title,
            'body' => $body,
            'keywords' => $keywords,
            'background_path' => (string) data_get($sourceMeta, 'background_path', ''),
            'language' => (string) data_get($sourceMeta, 'language', $workspace->content_language),
            'width' => (int) data_get($sourceMeta, 'width', TemplateImageGenerator::DEFAULT_WIDTH),
            'height' => (int) data_get($sourceMeta, 'height', TemplateImageGenerator::DEFAULT_HEIGHT),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function titleAndBodyFromPostContent(Post $post): array
    {
        $lines = Str::of((string) $post->content)
            ->replace(["\r\n", "\r"], "\n")
            ->trim()
            ->explode("\n")
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values();

        if ($lines->isEmpty()) {
            return ['', ''];
        }

        return [
            (string) $lines->first(),
            $lines->slice(1)->implode(' '),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function keywordsFromCopy(string $title, string $body): array
    {
        return Str::of("{$title} {$body}")
            ->squish()
            ->explode(' ')
            ->map(fn (string $word) => (string) Str::of($word)->trim(".,!?;:\"'()[]{}"))
            ->filter(fn (string $word) => mb_strlen($word) >= 4)
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeKeywords(mixed $keywords): array
    {
        return collect($keywords)
            ->filter(fn ($keyword) => is_string($keyword) && trim($keyword) !== '')
            ->map(fn (string $keyword) => trim($keyword))
            ->values()
            ->all();
    }

    /**
     * @return 'image_only'|'text_only'|'both'
     */
    private function resolveChangeMode(string $value): string
    {
        return match ($value) {
            'image_only', 'text_only', 'both' => $value,
            default => 'both',
        };
    }

    private function resolveSocialAccount(Post $post, Workspace $workspace): ?SocialAccount
    {
        $enabledAccount = $post->postPlatforms
            ->first(fn ($platform) => $platform->enabled && $platform->socialAccount);

        if ($enabledAccount?->socialAccount) {
            return $enabledAccount->socialAccount;
        }

        $anyAccount = $post->postPlatforms
            ->first(fn ($platform) => $platform->socialAccount);

        return $anyAccount?->socialAccount
            ?? $workspace->socialAccounts()->first();
    }

    /**
     * @param  array{path: string, source_meta: array<string, mixed>}  $rendered
     * @return array<string, mixed>
     */
    private function buildAiMediaItem(Workspace $workspace, array $rendered): array
    {
        $media = $workspace->media()->create([
            'collection' => 'ai-generated',
            'type' => MediaType::Image,
            'path' => $rendered['path'],
            'original_filename' => basename($rendered['path']),
            'mime_type' => 'image/webp',
            'size' => Storage::size($rendered['path']),
            'order' => 0,
        ]);

        return [
            'id' => $media->id,
            'path' => $media->path,
            'url' => $media->url,
            'type' => 'image',
            'mime_type' => 'image/webp',
            'source' => Source::Ai->value,
            'source_meta' => $rendered['source_meta'],
        ];
    }
}
