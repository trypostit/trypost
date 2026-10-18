<?php

declare(strict_types=1);

namespace App\Services\Inbox\Concerns;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\PollResult;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Services\Inbox\PollBudget;
use Carbon\CarbonImmutable;
use Closure;

trait ScansRecentPublications
{
    private const int MAX_PUBLICATIONS = 100;

    /**
     * Reads the comments of every recent publication, newest first. When the
     * poll budget runs out after a publication, it returns what it read and a
     * cursor that resumes after that publication; the scan after a complete
     * one starts from the newest again.
     *
     * @param  Closure(DiscoveredPublication): list<IncomingMessage>  $read
     */
    protected function scanPublications(SocialAccount $account, ?string $cursor, CarbonImmutable $since, Closure $read): PollResult
    {
        $publications = $this->recentPublications($account, $since);
        $resumeAfter = data_get(json_decode((string) $cursor, true), 'resume_after');
        $position = filled($resumeAfter)
            ? collect($publications)->search(fn (DiscoveredPublication $publication): bool => $publication->providerPostId === $resumeAfter)
            : false;
        $pending = $position === false ? $publications : array_slice($publications, $position + 1);
        $messages = [];

        foreach (array_values($pending) as $index => $publication) {
            $messages = [...$messages, ...$read($publication)];

            if ($index < count($pending) - 1 && app(PollBudget::class)->exhausted()) {
                return new PollResult($messages, (string) json_encode(['resume_after' => $publication->providerPostId]), partial: true);
            }
        }

        return new PollResult($messages, now()->toIso8601String());
    }

    /** @return list<DiscoveredPublication> */
    protected function recentPublications(SocialAccount $account, CarbonImmutable $since): array
    {
        $collector = app(PublicationHistoryCollectorFactory::class)->for($account);
        $found = [];
        $cursor = null;

        try {
            do {
                $page = $collector->page($account, $cursor, $since);
                $found = [...$found, ...$page->publications];
                $cursor = $page->nextCursor;
            } while (filled($cursor) && ! $page->providerExhausted && count($found) < self::MAX_PUBLICATIONS);
        } catch (AnalyticsCollectionException $exception) {
            throw match ($exception->category) {
                'authentication' => new TokenExpiredException($exception->getMessage()),
                'rate_limited', 'transient', 'delayed' => new PlatformUnavailableException($exception->getMessage()),
                default => $exception,
            };
        }

        return array_values(array_slice(array_filter(
            $found,
            fn (DiscoveredPublication $publication): bool => $publication->contentType !== PublicationContentType::Story
                && $publication->publishedAt->gte($since),
        ), 0, self::MAX_PUBLICATIONS));
    }

    protected function subjectFrom(DiscoveredPublication $publication): IncomingSubject
    {
        $thumbnail = data_get($publication->previewMetadata, 'thumbnail_url');

        return new IncomingSubject(
            platformId: $publication->providerPostId,
            text: $publication->excerpt,
            media: filled($thumbnail) ? [['type' => 'image', 'url' => (string) $thumbnail]] : [],
            url: $publication->permalink,
            publishedAt: $publication->publishedAt,
            remoteId: $publication->providerPostId,
            publication: $publication,
        );
    }
}
