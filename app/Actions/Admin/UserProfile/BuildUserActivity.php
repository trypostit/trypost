<?php

declare(strict_types=1);

namespace App\Actions\Admin\UserProfile;

use App\Enums\Activity\LogName;
use App\Enums\Changelog\Reaction;
use App\Http\Resources\Admin\AdminActivityResource;
use App\Http\Resources\App\Changelog\ChangelogEntryResource;
use App\Models\Activity;
use App\Models\ChangelogEntryView;
use App\Models\User;
use App\Support\Auth\DatabaseSessions;
use Carbon\CarbonImmutable;

class BuildUserActivity
{
    public const int HISTORY_LIMIT = 50;

    /**
     * What happened around the user: what backoffice admins did to them, the
     * sessions they are signed in with (`DatabaseSessions`: none under another
     * session driver; the id is hashed, never sent, and the last activity is
     * an ISO instant), and the changelog entries they opened.
     *
     * @return array<string, mixed>
     */
    public static function execute(User $user): array
    {
        $history = Activity::query()
            ->inLog(LogName::Admin)
            ->forSubject($user)
            ->with('causer')
            ->latest()
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $views = ChangelogEntryView::query()
            ->where('user_id', $user->id)
            ->with('entry')
            ->latest()
            ->get()
            ->filter(fn (ChangelogEntryView $view): bool => filled($view->entry));

        return [
            'history' => AdminActivityResource::collection($history)->resolve(),
            'sessions' => DatabaseSessions::of($user)
                ->map(fn (object $session): array => [
                    'id' => hash('sha256', $session->id),
                    'user_agent' => $session->user_agent,
                    'ip' => $session->ip_address,
                    'last_activity' => CarbonImmutable::createFromTimestampUTC($session->last_activity)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'changelog' => [
                'opened' => $views->count(),
                'liked' => $views->where('reaction', Reaction::Like)->count(),
                'disliked' => $views->where('reaction', Reaction::Dislike)->count(),
                'entries' => $views->map(fn (ChangelogEntryView $view): array => [
                    'viewed_at' => $view->created_at?->toIso8601String(),
                    'entry' => ChangelogEntryResource::make($view->entry->setAttribute('seen', true)->setAttribute('reaction', $view->reaction?->value))->resolve(),
                ])->values()->all(),
            ],
        ];
    }
}
