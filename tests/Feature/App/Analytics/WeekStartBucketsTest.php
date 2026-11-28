<?php

declare(strict_types=1);

use App\Dto\Analytics\DateRange;
use App\Enums\User\WeekStart;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Analytics\PeriodBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('weekly buckets follow the given week start whatever the language', function (string $locale, WeekStart $weekStart, string $firstEnd, string $secondStart) {
    app()->setLocale($locale);
    $range = new DateRange(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-30', 'UTC'));

    $buckets = app(PeriodBuckets::class)->for($range, $weekStart);

    expect($buckets[0])->toBe(['start' => '2026-09-01', 'end' => $firstEnd])
        ->and($buckets[1]['start'])->toBe($secondStart);
})->with([
    'english monday' => ['en', WeekStart::Monday, '2026-09-06', '2026-09-07'],
    'english sunday' => ['en', WeekStart::Sunday, '2026-09-05', '2026-09-06'],
    'portuguese monday' => ['pt-BR', WeekStart::Monday, '2026-09-06', '2026-09-07'],
    'arabic monday' => ['ar', WeekStart::Monday, '2026-09-06', '2026-09-07'],
    'arabic sunday' => ['ar', WeekStart::Sunday, '2026-09-05', '2026-09-06'],
]);

test('the insights posts chart buckets weeks by the viewer week start', function (WeekStart $weekStart, string $firstEnd) {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));
    $user = User::factory()->create(['timezone' => 'UTC', 'week_starts_on' => $weekStart, 'locale' => 'pt-BR']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->get(route('app.insights', ['range' => '30d']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.posts.resolution', 'weekly')
            ->where('report.posts.buckets.0.start', '2026-09-01')
            ->where('report.posts.buckets.0.end', $firstEnd)
            ->etc());
})->with([
    'monday' => [WeekStart::Monday, '2026-09-06'],
    'sunday' => [WeekStart::Sunday, '2026-09-05'],
]);
