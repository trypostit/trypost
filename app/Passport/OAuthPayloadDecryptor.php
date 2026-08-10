<?php

declare(strict_types=1);

namespace App\Passport;

use Defuse\Crypto\Crypto;
use Illuminate\Contracts\Encryption\Encrypter;
use Laravel\Passport\Passport;
use Throwable;

/**
 * Decrypt League OAuth2 auth-code / refresh-token request payloads using the
 * same key Passport passes to AuthorizationServer.
 */
class OAuthPayloadDecryptor
{
    public function __construct(private Encrypter $encrypter) {}

    /**
     * @return array<string, mixed>|null
     */
    public function decrypt(string $encrypted): ?array
    {
        try {
            $json = Crypto::decryptWithPassword(
                $encrypted,
                Passport::tokenEncryptionKey($this->encrypter),
            );
        } catch (Throwable) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Encrypt a payload the same way League's CryptTrait does (for tests).
     *
     * @param  array<string, mixed>  $payload
     */
    public function encrypt(array $payload): string
    {
        return Crypto::encryptWithPassword(
            json_encode($payload, JSON_THROW_ON_ERROR),
            Passport::tokenEncryptionKey($this->encrypter),
        );
    }
}
