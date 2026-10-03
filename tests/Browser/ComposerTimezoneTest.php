<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\PostPlatform\ContentType;
use App\Enums\User\TimeFormat;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;

function waitForComposerTimezoneTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForComposerTimezoneClosed(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

function waitForComposerTimezoneTrigger(mixed $page, string $label): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="composer-schedule-trigger"]')?.textContent.trim() === '{$label}') return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @return array{0: User, 1: Workspace}
 */
function composerTimezoneWorkspace(): array
{
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo', 'time_format' => TimeFormat::TwentyFourHour]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user->fresh(), $workspace];
}

function composerTimezoneChannel(Workspace $workspace, string $timezone, ?PostingSchedule $schedule = null): SocialAccount
{
    return SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => $timezone,
        'posting_schedule' => $schedule,
    ]);
}

function composerTimezoneSelect(mixed $page, SocialAccount ...$channels): void
{
    waitForComposerTimezoneTestId($page, 'composer-add-account');
    $page->click('@composer-add-account');

    foreach ($channels as $channel) {
        waitForComposerTimezoneTestId($page, "composer-account-option-{$channel->id}");
        $page->click("@composer-account-option-{$channel->id}");
    }
}

/**
 * Opens the date and time picker: straight away when a custom time is set,
 * through the When menu otherwise.
 */
function composerTimezoneOpenPicker(mixed $page): void
{
    waitForComposerTimezoneTestId($page, 'composer-schedule-trigger');
    $page->click('@composer-schedule-trigger');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="composer-schedule-picker"]') || document.querySelector('[data-testid="composer-schedule-custom"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    if (! $page->script('Boolean(document.querySelector(\'[data-testid="composer-schedule-picker"]\'))')) {
        $page->click('@composer-schedule-custom');
    }

    waitForComposerTimezoneTestId($page, 'composer-schedule-picker');
}

function composerTimezonePickFutureDay(mixed $page): string
{
    $query = '[...document.querySelectorAll("[data-testid^=composer-schedule-day-]")].find((day) => !day.hasAttribute("data-disabled") && !day.hasAttribute("data-outside-view") && !day.hasAttribute("data-zone-today"))?.dataset.testid.replace("composer-schedule-day-", "") ?? null';
    $day = $page->script($query);

    if ($day === null) {
        $page->click('@composer-schedule-calendar-next');
        $day = $page->script($query);
    }

    $page->click("@composer-schedule-day-{$day}");

    return $day;
}

function composerTimezoneTrigger(mixed $page): string
{
    return trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-trigger]").textContent'));
}

function composerTimezoneZoneLabel(mixed $page): string
{
    return trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-timezone]").textContent'));
}

function composerTimezoneLabel(CarbonImmutable $local): string
{
    return $local->format($local->year === now('Asia/Tokyo')->year ? 'M j' : 'M j, Y').", {$local->format('H:i')}";
}

test('one channel shows and takes times in its own zone', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $saoPaulo = composerTimezoneChannel($workspace, 'America/Sao_Paulo');
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerTimezoneSelect($page, $saoPaulo);
    $page->fill("@composer-caption-{$saoPaulo->id}", 'Zone check');
    composerTimezoneOpenPicker($page);

    expect(composerTimezoneZoneLabel($page))->toBe('America/Sao Paulo');

    $day = composerTimezonePickFutureDay($page);
    $page->fill('@composer-schedule-time-input', '1000')->click('@composer-schedule-done');
    $instant = CarbonImmutable::parse("{$day} 10:00", 'America/Sao_Paulo');

    expect(composerTimezoneTrigger($page))->toBe(composerTimezoneLabel($instant));

    $page->click('@composer-submit');
    waitForComposerTimezoneClosed($page);

    expect(Post::query()->where('workspace_id', $workspace->id)->sole()->scheduled_at->toIso8601String())
        ->toBe($instant->utc()->toIso8601String());
    $page->assertNoJavaScriptErrors();
});

test('channels in different zones use the user zone', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $saoPaulo = composerTimezoneChannel($workspace, 'America/Sao_Paulo');
    $warsaw = composerTimezoneChannel($workspace, 'Europe/Warsaw');
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerTimezoneSelect($page, $saoPaulo, $warsaw);
    composerTimezoneOpenPicker($page);

    expect(composerTimezoneZoneLabel($page))->toBe('Asia/Tokyo');
    $page->assertNoJavaScriptErrors();
});

test('changing the channels keeps the picked instant and shows it in the new zone', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $saoPaulo = composerTimezoneChannel($workspace, 'America/Sao_Paulo');
    $warsaw = composerTimezoneChannel($workspace, 'Europe/Warsaw');
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerTimezoneSelect($page, $saoPaulo);
    $page->fill("@composer-caption-{$saoPaulo->id}", 'Same moment everywhere');
    composerTimezoneOpenPicker($page);
    $day = composerTimezonePickFutureDay($page);
    $page->fill('@composer-schedule-time-input', '1000')->click('@composer-schedule-done');
    $instant = CarbonImmutable::parse("{$day} 10:00", 'America/Sao_Paulo');

    composerTimezoneSelect($page, $warsaw);
    waitForComposerTimezoneTrigger($page, composerTimezoneLabel($instant->setTimezone('Asia/Tokyo')));
    expect(composerTimezoneTrigger($page))->toBe(composerTimezoneLabel($instant->setTimezone('Asia/Tokyo')));

    $page->click("@composer-account-option-{$warsaw->id}");
    waitForComposerTimezoneTrigger($page, composerTimezoneLabel($instant));
    expect(composerTimezoneTrigger($page))->toBe(composerTimezoneLabel($instant));

    $page->click('@composer-submit');
    waitForComposerTimezoneClosed($page);

    expect(Post::query()->where('workspace_id', $workspace->id)->sole()->scheduled_at->toIso8601String())
        ->toBe($instant->utc()->toIso8601String());
    $page->assertNoJavaScriptErrors();
});

test('editing a scheduled post shows its time in the channel zone', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $saoPaulo = composerTimezoneChannel($workspace, 'America/Sao_Paulo');
    $instant = CarbonImmutable::parse(now('America/Sao_Paulo')->addDays(3)->format('Y-m-d').' 10:00', 'America/Sao_Paulo');
    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'scheduled_at' => $instant->utc()->toIso8601String(),
        'content' => 'Edit me',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $saoPaulo->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));
    waitForComposerTimezoneTrigger($page, composerTimezoneLabel($instant));

    expect(composerTimezoneTrigger($page))->toBe(composerTimezoneLabel($instant));

    composerTimezoneOpenPicker($page);
    expect(composerTimezoneZoneLabel($page))->toBe('America/Sao Paulo')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-time-input]").value'))->toBe('10:00');

    $page->click('@composer-schedule-done')->click('@composer-submit');
    waitForComposerTimezoneClosed($page);

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe($instant->utc()->toIso8601String());
    $page->assertNoJavaScriptErrors();
});

function composerTimezoneSchedule(): PostingSchedule
{
    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, composerTimezoneMorningSlot($day))->withTime($day, composerTimezoneEveningSlot($day));
    }

    return $schedule;
}

function composerTimezoneMorningSlot(int $weekday): string
{
    return sprintf('08:%02d', $weekday * 5);
}

function composerTimezoneEveningSlot(int $weekday): string
{
    return sprintf('%02d:20', 10 + $weekday);
}

/**
 * A fixed-offset zone where it is around noon right now, so a slot two hours
 * earlier is always past and one three hours later is always ahead.
 */
function composerTimezoneNoonZone(): string
{
    $offset = 12 - now('UTC')->hour;

    return match (true) {
        $offset === 0 => 'UTC',
        $offset > 0 => "Etc/GMT-{$offset}",
        default => 'Etc/GMT+'.abs($offset),
    };
}

test('one channel offers its posting slots for the picked day', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $saoPaulo = composerTimezoneChannel($workspace, 'America/Sao_Paulo', composerTimezoneSchedule());
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerTimezoneSelect($page, $saoPaulo);
    $page->fill("@composer-caption-{$saoPaulo->id}", 'From a slot');
    composerTimezoneOpenPicker($page);
    $day = composerTimezonePickFutureDay($page);
    $weekday = CarbonImmutable::parse($day)->dayOfWeek;
    $evening = composerTimezoneEveningSlot($weekday);
    $testId = fn (string $time): string => 'composer-schedule-slot-'.str_replace(':', '', $time);
    waitForComposerTimezoneTestId($page, $testId($evening));

    $page->assertVisible('@'.$testId(composerTimezoneMorningSlot($weekday)))
        ->assertMissing('@'.$testId(composerTimezoneEveningSlot(($weekday + 1) % 7)))
        ->click('@'.$testId($evening))
        ->assertValue('@composer-schedule-time-input', $evening)
        ->click('@composer-schedule-done');
    $instant = CarbonImmutable::parse("{$day} {$evening}", 'America/Sao_Paulo');

    expect(composerTimezoneTrigger($page))->toBe(composerTimezoneLabel($instant));

    $page->click('@composer-submit');
    waitForComposerTimezoneClosed($page);

    expect(Post::query()->where('workspace_id', $workspace->id)->sole()->scheduled_at->toIso8601String())
        ->toBe($instant->utc()->toIso8601String());
    $page->assertNoJavaScriptErrors();
});

test('several channels hide the posting slots', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $first = composerTimezoneChannel($workspace, 'America/Sao_Paulo', composerTimezoneSchedule());
    $second = composerTimezoneChannel($workspace, 'America/Sao_Paulo', composerTimezoneSchedule());
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerTimezoneSelect($page, $first, $second);
    composerTimezoneOpenPicker($page);
    composerTimezonePickFutureDay($page);

    expect(composerTimezoneZoneLabel($page))->toBe('America/Sao Paulo');
    $page->assertMissing('@composer-schedule-slots')->assertNoJavaScriptErrors();
});

test('past posting slots of today are disabled', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $zone = composerTimezoneNoonZone();
    $channel = composerTimezoneChannel($workspace, $zone, PostingSchedule::empty()
        ->withTime(now($zone)->dayOfWeek, '10:00')
        ->withTime(now($zone)->dayOfWeek, '15:00'));
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerTimezoneSelect($page, $channel);
    composerTimezoneOpenPicker($page);
    waitForComposerTimezoneTestId($page, 'composer-schedule-slot-1500');

    expect($page->script('document.querySelector("[data-testid=composer-schedule-slot-1000]").disabled'))->toBeTrue()
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-slot-1500]").disabled'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('posting slots already taken by a scheduled post are disabled', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $zone = composerTimezoneNoonZone();
    $channel = composerTimezoneChannel($workspace, $zone, PostingSchedule::empty()
        ->withTime(now($zone)->dayOfWeek, '15:00')
        ->withTime(now($zone)->dayOfWeek, '16:00'));
    $taken = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'scheduled_at' => CarbonImmutable::parse(now($zone)->format('Y-m-d').' 15:00', $zone)->utc(),
    ]);
    PostPlatform::factory()->create(['post_id' => $taken->id, 'social_account_id' => $channel->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerTimezoneSelect($page, $channel);
    composerTimezoneOpenPicker($page);
    waitForComposerTimezoneTestId($page, 'composer-schedule-slot-1600');

    expect($page->script('document.querySelector("[data-testid=composer-schedule-slot-1500]").disabled'))->toBeTrue()
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-slot-1600]").disabled'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('a day whose posting slots are all past hides them', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $zone = composerTimezoneNoonZone();
    $channel = composerTimezoneChannel($workspace, $zone, PostingSchedule::empty()
        ->withTime(now($zone)->dayOfWeek, '09:00')
        ->withTime(now($zone)->dayOfWeek, '10:00'));
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerTimezoneSelect($page, $channel);
    composerTimezoneOpenPicker($page);

    $page->assertMissing('@composer-schedule-slots')->assertNoJavaScriptErrors();
});

test('the picker marks today in the channel zone, not the browser zone', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $channel = composerTimezoneChannel($workspace, 'Pacific/Pago_Pago');
    $this->actingAs($user);

    $page = visit(route('app.posts.create'))->withTimezone('Pacific/Kiritimati');
    composerTimezoneSelect($page, $channel);
    composerTimezoneOpenPicker($page);
    $today = now('Pacific/Pago_Pago')->toDateString();

    if (! $page->script("Boolean(document.querySelector('[data-testid=\"composer-schedule-day-{$today}\"]:not([data-outside-view])'))")) {
        $page->click('@composer-schedule-calendar-prev');
    }

    expect($page->script('[...document.querySelectorAll("[data-zone-today]:not([data-outside-view])")].map((day) => day.dataset.testid)'))
        ->toBe(["composer-schedule-day-{$today}"]);
    $page->assertNoJavaScriptErrors();
});

test('a calendar hour opens the composer at the same moment, shown in the channel zone', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $warsaw = composerTimezoneChannel($workspace, 'Europe/Warsaw');
    $day = now('America/Sao_Paulo')->addDays(2)->format('Y-m-d');
    $this->actingAs($user);

    $page = visit(route('app.channels.calendar', ['account' => $warsaw->id, 'view' => 'week', 'week' => $day, 'tz' => 'America/Sao_Paulo']));
    waitForComposerTimezoneTestId($page, "calendar-add-{$day}-10");
    $page->click("@calendar-add-{$day}-10");
    waitForComposerTimezoneTestId($page, "composer-caption-{$warsaw->id}");

    $instant = CarbonImmutable::parse("{$day} 10:00", 'America/Sao_Paulo');
    waitForComposerTimezoneTrigger($page, composerTimezoneLabel($instant->setTimezone('Europe/Warsaw')));
    expect(composerTimezoneTrigger($page))->toBe(composerTimezoneLabel($instant->setTimezone('Europe/Warsaw')));

    $page->fill("@composer-caption-{$warsaw->id}", 'From the calendar')->click('@composer-submit');
    waitForComposerTimezoneClosed($page);

    expect(Post::query()->where('workspace_id', $workspace->id)->sole()->scheduled_at->toIso8601String())
        ->toBe($instant->utc()->toIso8601String());
    $page->assertNoJavaScriptErrors();
});

test('a calendar day opens the composer at 9:00 of the display zone', function () {
    [$user, $workspace] = composerTimezoneWorkspace();
    $warsaw = composerTimezoneChannel($workspace, 'Europe/Warsaw');
    $day = now('America/Sao_Paulo')->addDays(2)->format('Y-m-d');
    $this->actingAs($user);

    $page = visit(route('app.channels.calendar', ['account' => $warsaw->id, 'view' => 'month', 'month' => $day, 'tz' => 'America/Sao_Paulo']));
    waitForComposerTimezoneTestId($page, "calendar-add-{$day}");
    $page->click("@calendar-add-{$day}");
    waitForComposerTimezoneTestId($page, "composer-caption-{$warsaw->id}");

    $instant = CarbonImmutable::parse("{$day} 09:00", 'America/Sao_Paulo');
    waitForComposerTimezoneTrigger($page, composerTimezoneLabel($instant->setTimezone('Europe/Warsaw')));

    expect(composerTimezoneTrigger($page))->toBe(composerTimezoneLabel($instant->setTimezone('Europe/Warsaw')));
    $page->assertNoJavaScriptErrors();
});
