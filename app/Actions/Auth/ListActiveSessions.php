<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ListActiveSessions
{
    /**
     * The user's sessions, most recently active first. Only the database
     * session driver keeps them, so any other driver lists none.
     *
     * @return list<array{id: string, ip_address: string|null, user_agent: string|null, last_active: string, last_page: string|null, is_current: bool}>
     */
    public static function execute(User $user, string $currentSessionId): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                'last_page' => self::lastPage($session->payload),
                'is_current' => $session->id === $currentSessionId,
            ])->values()->all();
    }

    /**
     * The path of the last page the session opened, read from the URL Laravel
     * keeps in the session payload. The query string is left out so no token
     * or filter in it is shown. Encrypted payloads cannot be read and give none.
     */
    private static function lastPage(string $payload): ?string
    {
        if (config('session.encrypt')) {
            return null;
        }

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            return null;
        }

        $data = config('session.serialization') === 'json'
            ? json_decode($decoded, true)
            : @unserialize($decoded, ['allowed_classes' => false]);

        $url = data_get($data, '_previous.url');

        return is_string($url) ? (parse_url($url, PHP_URL_PATH) ?: null) : null;
    }
}
