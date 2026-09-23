<?php

declare(strict_types=1);

use App\Console\Commands\Analytics\BackfillExistingAnalytics;
use App\Console\Commands\Analytics\DispatchAccountDailyAnalytics;
use App\Console\Commands\Analytics\DispatchPublicationDiscovery;
use App\Console\Commands\Analytics\DispatchPublicationMetrics;
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
Schedule::command(BackfillExistingAnalytics::class)->hourly()->withoutOverlapping(120)->onOneServer();
Schedule::command(DispatchAccountDailyAnalytics::class)
    ->dailyAt('02:00')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command(DispatchPublicationDiscovery::class)
    ->dailyAt('03:00')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command(DispatchPublicationMetrics::class)
    ->dailyAt('04:00')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::job(new FinalizeAccountDailySnapshots)
    ->name('analytics:finalize-account-daily')
    ->dailyAt('23:30')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
Schedule::job(new FinalizeAccountDailySnapshots(daysAgo: 1))
    ->name('analytics:finalize-account-daily-recovery')
    ->dailyAt('00:30')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
