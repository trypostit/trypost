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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BuildUserActivity
{
    public const int HISTORY_LIMIT = 50;

    /**
     * What happened around the user: what backoffice admins did to them, the
     * sessions they are signed in with (shaped like `ListActiveSessions`, plus
     * the IP; the session id is hashed, never sent), and the changelog entries
     * they opened.
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
            'sessions' => DB::table(config()->string('session.table'))
                ->where('user_id', $user->id)
                ->orderByDesc('last_activity')
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(fn (object $session): array => [
                    'id' => hash('sha256', (string) $session->id),
                    'user_agent' => $session->user_agent,
                    'ip' => $session->ip_address,
                    'last_active' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity)->diffForHumans(),
                    'last_activity' => CarbonImmutable::createFromTimestampUTC((int) $session->last_activity)->toIso8601String(),
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
