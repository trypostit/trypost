<?php

declare(strict_types=1);

use App\Console\Commands\Automation\FireScheduleTriggers;
use App\Console\Commands\Automation\ProcessAutomationDelays;
use App\Console\Commands\Automation\PruneDryRunAutomationRuns;
use App\Console\Commands\Automation\RecoverStuckAutomationRuns;
use App\Console\Commands\CheckSocialConnections;
use App\Console\Commands\ProcessScheduledPosts;
use App\Console\Commands\RecoverStuckPosts;
use App\Console\Commands\RefreshExpiringTokens;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ProcessScheduledPosts::class)->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command(CheckSocialConnections::class)->daily()->withoutOverlapping()->onOneServer();
Schedule::command(RefreshExpiringTokens::class)->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command(RecoverStuckPosts::class)->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command(FireScheduleTriggers::class)->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command(ProcessAutomationDelays::class)->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command(RecoverStuckAutomationRuns::class)->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command(PruneDryRunAutomationRuns::class)->everyTenMinutes()->withoutOverlapping()->onOneServer();
