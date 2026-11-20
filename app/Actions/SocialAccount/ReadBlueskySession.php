<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Dto\BlueskySession;
use App\Http\Requests\App\Bluesky\ReadBlueskySessionRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Validator;

class ReadBlueskySession
{
    /**
     * The session in a Bluesky `createSession` or `getSession` response,
     * validated against ReadBlueskySessionRequest. Null when the call failed
     * or the body does not hold a valid session.
     */
    public static function execute(Response $response): ?BlueskySession
    {
        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            return null;
        }

        $validator = Validator::make($data, (new ReadBlueskySessionRequest)->rules());

        if ($validator->fails()) {
            return null;
        }

        $session = $validator->validated();

        return new BlueskySession(
            did: data_get($session, 'did'),
            handle: data_get($session, 'handle'),
            accessToken: data_get($session, 'accessJwt'),
            refreshToken: data_get($session, 'refreshJwt'),
            emailConfirmed: data_get($session, 'emailConfirmed'),
        );
    }
}
