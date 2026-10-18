<?php

declare(strict_types=1);

namespace App\Support\Inbox;

use Illuminate\Http\Request;

class WebhookSignature
{
    private const array META_SECRETS = [
        'services.facebook.client_secret',
        'services.instagram.client_secret',
        'services.threads.client_secret',
    ];

    /**
     * Meta signs each delivery with the App Secret of the app that sent it
     * (Facebook Login, Instagram Login or Threads): `sha256=` plus the
     * HMAC-SHA256 of the raw body.
     *
     * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started
     */
    public static function meta(Request $request): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($signature === '') {
            return false;
        }

        foreach (self::META_SECRETS as $key) {
            $secret = (string) config($key);

            if ($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
                return true;
            }
        }

        return false;
    }
}
