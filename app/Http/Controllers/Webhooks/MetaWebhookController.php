<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Jobs\Inbox\HandleMetaWebhook;
use App\Support\Inbox\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MetaWebhookController extends Controller
{
    /**
     * Meta's subscription check. PHP reads the dotted `hub.*` query keys as `hub_*`.
     *
     * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started
     */
    public function verify(Request $request): Response
    {
        abort_unless(Platform::inboxModuleEnabled(), SymfonyResponse::HTTP_NOT_FOUND);

        $token = (string) config('trypost.inbox.webhooks.meta_verify_token');
        $sentToken = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        abort_if(
            $token === ''
                || $request->query('hub_mode') !== 'subscribe'
                || ! is_string($sentToken)
                || ! is_string($challenge)
                || ! hash_equals($token, $sentToken),
            SymfonyResponse::HTTP_FORBIDDEN,
        );

        return response($challenge, SymfonyResponse::HTTP_OK)->header('Content-Type', 'text/plain');
    }

    public function store(Request $request): Response
    {
        abort_unless(Platform::inboxModuleEnabled(), SymfonyResponse::HTTP_NOT_FOUND);

        if (! WebhookSignature::meta($request)) {
            $context = ['headers' => array_keys($request->headers->all())];

            Cache::add('inbox:meta-webhook-signature-logged', true, now()->addHour())
                ? Log::warning('Meta webhook signature rejected', $context)
                : Log::debug('Meta webhook signature rejected', $context);

            abort(SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        $payload = (array) $request->json()->all();

        if (HandleMetaWebhook::isRelevant($payload)) {
            HandleMetaWebhook::dispatch($payload);
        }

        return response('', SymfonyResponse::HTTP_OK);
    }
}
