<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\TokenRedactor;
use App\Support\Social\NetworkLimitReset;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use RuntimeException;

abstract class SocialPublishException extends RuntimeException
{
    /**
     * When the network said its limit lifts, for a RateLimit refusal.
     */
    public ?CarbonInterface $retryAt = null;

    private bool $rejectedByNetwork = false;

    public function __construct(
        public readonly string $userMessage,
        public readonly ErrorCategory $category,
        public readonly ?string $platformErrorCode = null,
        public readonly ?string $rawResponse = null,
    ) {
        parent::__construct($userMessage);
    }

    /**
     * @return array{platform: string, category: string, platform_error_code: ?string, user_message: string, raw_response: ?string}
     */
    public function context(): array
    {
        return [
            'platform' => $this->platform(),
            'category' => $this->category->value,
            'platform_error_code' => $this->platformErrorCode,
            'user_message' => $this->userMessage,
            'raw_response' => TokenRedactor::redact($this->rawResponse),
        ];
    }

    public function isLimit(): bool
    {
        return $this->category === ErrorCategory::RateLimit;
    }

    /**
     * Set by a provider mapper on a documented rejection code: the network
     * refused the post and only the user can fix it. Our own failures
     * (downloads, storage, configuration, invariants) are never marked.
     */
    public function asNetworkRejection(): static
    {
        return $this->asNetworkRejectionIf(true);
    }

    /**
     * Marks the exception only when the mapper lists its documented code as
     * caused by the user's own content, media, account or grants. A code
     * caused by our app, request or credentials stays reported.
     */
    public function asNetworkRejectionIf(bool $causedByUser): static
    {
        $this->rejectedByNetwork = $causedByUser && $this->category->needsUserAction();

        return $this;
    }

    public function isNetworkRejection(): bool
    {
        return $this->rejectedByNetwork;
    }

    /**
     * Reads the network's reset time from a limit refusal.
     */
    public function withNetworkReset(Response $response): static
    {
        if ($this->isLimit()) {
            $this->retryAt = NetworkLimitReset::from($response);
        }

        return $this;
    }

    /**
     * The provider's own explanation for an error we do not map, read from
     * its documented message fields in order. Display only: never classify
     * by it. A 5xx body is never shown.
     */
    protected static function providerMessage(Response $response, string ...$paths): ?string
    {
        if ($response->serverError()) {
            return null;
        }

        foreach ($paths as $path) {
            $message = self::filledMessage(data_get($response->json(), $path));

            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }

    /**
     * A provider message worth showing: a string with text in it.
     */
    protected static function filledMessage(mixed $message): ?string
    {
        return is_string($message) && filled($message) ? $message : null;
    }

    abstract public static function fromApiResponse(mixed $response): static;

    abstract public function platform(): string;
}
