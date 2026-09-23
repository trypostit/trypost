<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Illuminate\Http\Client\Response;

class InvalidPublicationCursor
{
    public static function matches(Response $response): bool
    {
        if ($response->status() !== 400 && ! $response->successful()) {
            return false;
        }

        $reason = data_get($response->json(), 'error.errors.0.reason');

        if ($reason === 'invalidPageToken') {
            return true;
        }

        foreach (['error.message', 'message', 'detail', 'title', 'error_description'] as $path) {
            $message = data_get($response->json(), $path);

            if (is_string($message)
                && preg_match('/\b(invalid|expired|malformed)\b/i', $message)
                && preg_match('/\b(cursor|page[\s_-]?token|pagination[\s_-]?token|bookmark)\b/i', $message)) {
                return true;
            }
        }

        return false;
    }
}
