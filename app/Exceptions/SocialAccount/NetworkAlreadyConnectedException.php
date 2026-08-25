<?php

declare(strict_types=1);

namespace App\Exceptions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use RuntimeException;

class NetworkAlreadyConnectedException extends RuntimeException
{
    public function __construct(
        public readonly Platform $platform,
        public readonly string $messageKey = 'network_taken',
        ?string $reason = null,
    ) {
        parent::__construct($reason ?? "This workspace already has a {$platform->network()} account connected.");
    }

    /**
     * The provider handed back an account other than the one being reconnected,
     * which is a different problem from the network slot being taken.
     */
    public static function identityMismatch(Platform $platform): self
    {
        return new self($platform, 'wrong_account', "The provider returned an identity other than the {$platform->network()} card being reconnected.");
    }

    /**
     * Another connect on this network holds the lock. Carried on this exception
     * so it lands in the messageKey branch every connect flow already handles,
     * rather than the generic catch that files a normal race as an error.
     */
    public static function connectInProgress(Platform $platform): self
    {
        return new self($platform, 'busy', "Another {$platform->network()} connect is still finishing.");
    }
}
