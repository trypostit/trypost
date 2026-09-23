<?php

declare(strict_types=1);

use App\Console\Commands\Analytics\DispatchAccountDailyAnalytics;
use App\Console\Commands\CheckSocialConnections;
use App\Console\Commands\CheckUpcomingPostConnections;
use App\Console\Commands\ProcessScheduledPosts;
use App\Console\Commands\PruneWebhookLogs;
use App\Console\Commands\ReconcileGoogleBusinessPosts;
use App\Console\Commands\RecoverStuckPosts;
use App\Console\Commands\RefreshExpiringTokens;
use App\Console\Commands\Repurpose\PollRepurposes;
use App\Jobs\Analytics\FinalizeAccountDailySnapshots;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ProcessScheduledPosts::class)->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command(CheckSocialConnections::class)->daily()->withoutOverlapping()->onOneServer();
Schedule::command(CheckUpcomingPostConnections::class)->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command(RefreshExpiringTokens::class)->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command(RecoverStuckPosts::class)->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command(ReconcileGoogleBusinessPosts::class)->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command(PruneWebhookLogs::class)->daily()->withoutOverlapping()->onOneServer();
Schedule::command(PollRepurposes::class)->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command(DispatchAccountDailyAnalytics::class)
    ->dailyAt('02:00')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::job(new FinalizeAccountDailySnapshots)
    ->dailyAt('23:30')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
