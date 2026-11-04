<?php

declare(strict_types=1);

namespace App\Exceptions\Analytics;

use App\Models\SocialAccount;
use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Client\Response;

class AnalyticsCollectionException extends Exception
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?CarbonImmutable $retryAt = null,
        public readonly bool $gone = false,
        public readonly bool $missingObject = false,
        public readonly bool $deletedOnNetwork = false,
    ) {
        parent::__construct($message);
    }

    public static function unsupported(string $message): self
    {
        return new self('unsupported', $message);
    }

    public static function malformed(string $message): self
    {
        return new self('malformed', $message);
    }

    /**
     * The collector confirmed the network deleted the post, so the post itself is
     * marked deleted, not only its publication (a bare 404 or 410 is only `gone`).
     */
    public static function deletedOnNetwork(string $message): self
    {
        return new self('malformed', $message, gone: true, deletedOnNetwork: true);
    }

    /**
     * Refuses a read before any request when the account's stored grant lacks
     * a scope it needs, recorded as the permission refusal the network would answer.
     *
     * @param  string|list<string>  ...$requirements
     *
     * @throws self
     */
    public static function unlessGranted(SocialAccount $account, string|array ...$requirements): void
    {
        $missing = $account->missingScope(...$requirements);

        if ($missing !== null) {
            throw new self('permission', "account grant lacks the {$missing} scope");
        }
    }

    /**
     * `$missingObject` is set by a collector that recognises the network's documented
     * "this post does not exist" answer, to confirm a deletion before reporting it.
     */
    public static function fromResponse(Response $response, string $operation, bool $missingObject = false): self
    {
        $reason = (string) data_get($response->json(), 'error.errors.0.reason', '');
        $category = match (true) {
            $response->status() === 429,
            in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'], true) => 'rate_limited',
            $response->status() === 401 => 'authentication',
            $response->status() === 403 => 'permission',
            $response->serverError() => 'transient',
            default => 'malformed',
        };

        return new self(
            $category,
            "{$operation} failed with HTTP {$response->status()}",
            RetryAfter::from($response),
            in_array($response->status(), [404, 410], true),
            $missingObject,
        );
    }
}
