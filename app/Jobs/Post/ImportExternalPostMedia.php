<?php

declare(strict_types=1);

namespace App\Jobs\Post;

use App\Actions\Media\DeleteOwnedMedia;
use App\Actions\Media\SyncOwnedMedia;
use App\Dto\ImportedFile;
use App\Dto\RemoteFile;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\AnalyticsPublication;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Post\ExternalMedia\ExternalMediaResolverFactory;
use App\Support\Media\MediaCopyBatch;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Copies an imported post's media from the network into the bucket, in network
 * order, falling back to the publication cover.
 */
class ImportExternalPostMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const array WITHOUT_MEDIA = [
        PublicationContentType::Text,
        PublicationContentType::Link,
        PublicationContentType::Poll,
    ];

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $uniqueFor = 3600;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public string $postId)
    {
        $this->onQueue(ImportRemoteMedia::QUEUE);
        $this->timeout = ImportRemoteMedia::timeoutSeconds();
    }

    public function uniqueId(): string
    {
        return $this->postId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $platform = $this->platform();

        return $platform !== null && app(ExternalMediaResolverFactory::class)->for($platform)->callsProvider()
            ? [new RateLimited('external-post-media')]
            : [];
    }

    public function providerRateLimitKey(): string
    {
        return $this->platform()?->network() ?? 'missing';
    }

    public function handle(ExternalMediaResolverFactory $resolvers, RemoteMediaImporter $importer): void
    {
        [$post, $publication, $account] = $this->target();

        if ($post === null) {
            return;
        }

        $deadline = now()->addSeconds(intdiv($this->timeout, 2));
        $uploads = $account === null
            ? []
            : $this->download($post->workspace, $importer, $deadline, fn (): array => $this->files($resolvers, $account, $publication));

        $this->attach($post, $uploads !== [] ? $uploads : $this->cover($post->workspace, $importer, $publication));
    }

    public function failed(?Throwable $exception): void
    {
        rescue(function (): void {
            [$post, $publication] = $this->target();

            if ($post !== null) {
                $this->attach($post, $this->cover($post->workspace, app(RemoteMediaImporter::class), $publication));
            }
        });
    }

    private function platform(): ?Platform
    {
        return once(fn (): ?Platform => PostPlatform::query()->where('post_id', $this->postId)->value('platform'));
    }

    /**
     * @return array{0: ?Post, 1: ?AnalyticsPublication, 2: ?SocialAccount}
     */
    private function target(): array
    {
        $post = Post::query()
            ->imported()
            ->with(['postPlatforms.socialAccount', 'postPlatforms.analyticsPublication'])
            ->find($this->postId);
        $target = $post?->postPlatforms->first();
        $publication = $target?->analyticsPublication;

        if ($post === null
            || $publication === null
            || ($post->media ?? []) !== []
            || in_array($publication->content_type, self::WITHOUT_MEDIA, true)) {
            return [null, null, null];
        }

        return [$post, $publication, $target->socialAccount];
    }

    /**
     * @return list<RemoteFile>
     */
    private function files(ExternalMediaResolverFactory $resolvers, SocialAccount $account, AnalyticsPublication $publication): array
    {
        try {
            return $resolvers->for($account->platform)->files($account, $publication);
        } catch (RequestException $exception) {
            if ($exception->response->status() === Response::HTTP_TOO_MANY_REQUESTS || $exception->response->serverError()) {
                throw $exception;
            }

            return [];
        }
    }

    /**
     * @param  Closure(): list<RemoteFile>  $resolve
     * @return list<Media>
     */
    private function download(Workspace $workspace, RemoteMediaImporter $importer, CarbonInterface $deadline, Closure $resolve): array
    {
        $files = array_values($resolve());
        $refreshed = false;
        $uploads = [];

        for ($index = 0; $index < count($files); $index++) {
            $imported = $this->importFile($workspace, $importer, $files[$index], [MediaType::Image, MediaType::Video], $deadline);

            if ($imported->failure === ImportedFile::UNREACHABLE && ! $refreshed) {
                $refreshed = true;
                $files = array_values($resolve());
                $imported = isset($files[$index])
                    ? $this->importFile($workspace, $importer, $files[$index], [MediaType::Image, MediaType::Video], $deadline)
                    : $imported;
            }

            if ($imported->succeeded()) {
                $uploads[] = $imported->media;
            }
        }

        return $uploads;
    }

    /**
     * @return list<Media>
     */
    private function cover(Workspace $workspace, RemoteMediaImporter $importer, AnalyticsPublication $publication): array
    {
        $url = data_get($publication->preview_metadata, 'thumbnail_url');

        if (! is_string($url) || $url === '') {
            return [];
        }

        $imported = $this->importFile($workspace, $importer, RemoteFile::fromUrl($url), [MediaType::Image], now()->addSeconds(intdiv($this->timeout, 4)));

        return $imported->succeeded() ? [$imported->media] : [];
    }

    /**
     * @param  array<MediaType>  $types
     */
    private function importFile(Workspace $workspace, RemoteMediaImporter $importer, RemoteFile $file, array $types, CarbonInterface $deadline): ImportedFile
    {
        return $importer->import($workspace, $file, $types, $this->timeout, $deadline, followRedirects: true);
    }

    /**
     * @param  list<Media>  $uploads
     */
    private function attach(Post $post, array $uploads): void
    {
        if ($uploads === []) {
            return;
        }

        $ids = array_map(fn (Media $media): string => $media->id, $uploads);

        try {
            MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($ids, $post): void {
                $locked = Post::query()->whereKey($post->id)->lockForUpdate()->first();

                if ($locked === null || ($locked->media ?? []) !== []) {
                    DeleteOwnedMedia::forRows($ids);

                    return;
                }

                SyncOwnedMedia::execute($locked, array_map(fn (string $id): array => ['id' => $id], $ids), $batch);
            });
        } catch (Throwable $exception) {
            DB::transaction(fn () => DeleteOwnedMedia::forRows($ids));

            throw $exception;
        }
    }
}
