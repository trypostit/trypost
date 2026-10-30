<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildInsightsPdf;
use App\Actions\Analytics\ListChannelPublicationPerformance;
use App\Broadcasting\InsightsPdfChannel;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Events\InsightsPdfExportFinished;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\GenerateInsightsPdf;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\Analytics\InsightsPdfExports;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Pdf::fake();
    Storage::fake();
    Http::fake(['*' => Http::response('', Response::HTTP_NOT_FOUND)]);
    $this->travelTo('2026-09-30 12:00 UTC');

    $this->user = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'name' => 'Acme Studio']);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram, 'username' => 'alpha']);

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->instagram->id,
        'social_account_key' => $this->instagram->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $this->instagram->platform_user_id,
        'date' => '2026-09-20',
        'followers_count' => 500,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->instagram->id,
        'social_account_key' => $this->instagram->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'excerpt' => 'Launch day recap',
        'provider_published_at' => CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-15',
        'reactions_count' => 40,
        'comments_count' => 3,
        'impressions_count' => 400,
    ]);
});

test('a workspace PDF renders the chosen sections and downloads once ready', function () {
    $response = $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), [
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'name' => 'September report',
            'sections' => ['summary', 'top_posts_reactions', 'performance'],
        ])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonPath('status', InsightsPdfExports::READY);

    $id = $response->json('id');

    Pdf::assertViewIs('pdf.insights.report');
    Pdf::assertViewHas('title', 'September report');
    Pdf::assertSee(['September report', 'Launch day recap', trans_choice('analytics.insights.export.pdf.top_posts.by_reactions', 1, ['count' => 1])]);
    Pdf::assertDontSee(trans_choice('analytics.insights.export.pdf.top_posts.by_comments', 1, ['count' => 1]));

    $this->getJson(route('app.insights.pdf.show', $id))
        ->assertOk()
        ->assertJsonPath('status', InsightsPdfExports::READY)
        ->assertJsonPath('download_url', route('app.insights.pdf.download', $id));

    $this->get(route('app.insights.pdf.download', $id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('trypost-insights-2026-09-30.pdf');
});

test('a PDF renders in the language of the user who asked, without raw keys', function (Locale $locale) {
    $this->user->update(['locale' => $locale]);

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), [
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'sections' => ['summary', 'top_posts_reactions', 'top_posts_comments', 'performance', 'channels'],
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee(__('analytics.insights.export.pdf.cover.eyebrow', [], $locale->value));
    Pdf::assertDontSee(['analytics.', 'common.']);
})->with(Locale::cases());

test('a PDF without a report name is titled with the workspace name', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertViewHas('title', 'Acme Studio');
});

test('the cover uses the workspace logo until a report logo is uploaded', function () {
    $this->workspace->addMediaFromStoredPath(
        tap('medias/workspace-logo.png', fn (string $path) => Storage::put($path, UploadedFile::fake()->image('logo.png', 40, 40)->getContent())),
        'logo.png',
        'image/png',
        100,
        'logo',
    );

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee('src="data:image/png;base64,');
});

test('the cover logo is kept for the next export until it is removed', function () {
    $this->actingAs($this->user)
        ->post(route('app.insights.pdf.store'), [
            'sections' => ['summary'],
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    $logo = $this->workspace->fresh()->getFirstMedia('report_logo');

    expect($logo)->not->toBeNull();
    Pdf::assertSee('src="data:image/png;base64,');

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect($this->workspace->fresh()->getFirstMedia('report_logo')?->id)->toBe($logo->id);

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary'], 'remove_logo' => true])
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect($this->workspace->fresh()->getFirstMedia('report_logo'))->toBeNull();
});

test('a channel PDF renders its metrics and post table', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.channels.insights.pdf.store', $this->instagram), [
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'sections' => ['summary', 'metrics', 'posts'],
        ])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonPath('status', InsightsPdfExports::READY);

    Pdf::assertViewHas('scope', 'channel');
    Pdf::assertSee([__('analytics.channel.metrics_card.title'), __('analytics.channel.performance'), 'Launch day recap']);
});

test('a table shows six metric columns at most and says how many were left out', function () {
    $columns = ['posts', 'reactions', 'comments', 'engagement_rate', 'reposts', 'impressions', 'clicks', 'views'];

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['performance'], 'columns' => $columns])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee([__('analytics.channel.metrics.impressions.label'), __('analytics.insights.export.pdf.columns_note', ['shown' => BuildInsightsPdf::MAX_COLUMNS, 'total' => 8])]);
    Pdf::assertDontSee([__('analytics.channel.metrics.clicks.label'), __('analytics.channel.metrics.views.label')]);
});

test('a PDF needs at least one section the page offers', function (array $sections, string $error) {
    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => $sections])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with([
    'none' => [[], 'sections'],
    'a channel-only section' => [['metrics'], 'sections.0'],
]);

test('only the user who asked can follow or download an export', function () {
    $id = $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->json('id');
    $other = User::factory()->create();
    $other->update(['current_workspace_id' => Workspace::factory()->create(['user_id' => $other->id])->id]);

    $this->actingAs($other)->getJson(route('app.insights.pdf.show', $id))->assertNotFound();
    $this->actingAs($other)->get(route('app.insights.pdf.download', $id))->assertNotFound();
});

test('a channel of another workspace cannot be exported', function () {
    $foreign = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $this->actingAs($this->user)
        ->postJson(route('app.channels.insights.pdf.store', $foreign), ['sections' => ['summary']])
        ->assertNotFound();
});

test('a render that fails marks the export as failed', function () {
    $this->mock(BuildInsightsPdf::class)
        ->shouldReceive('forWorkspace')
        ->andThrow(new RuntimeException('Chromium crashed'));

    $id = InsightsPdfExports::start($this->user, 'report.pdf');

    expect(fn () => GenerateInsightsPdf::dispatch($id, $this->user->id, $this->workspace->id, null, [], ['name' => null, 'sections' => ['summary'], 'columns' => []]))
        ->toThrow(RuntimeException::class);

    $this->actingAs($this->user)
        ->getJson(route('app.insights.pdf.show', $id))
        ->assertOk()
        ->assertJsonPath('status', InsightsPdfExports::FAILED)
        ->assertJsonPath('download_url', null);
});

test('old PDFs are pruned', function () {
    $old = InsightsPdfExports::DIRECTORY.'/old.pdf';
    $new = InsightsPdfExports::DIRECTORY.'/new.pdf';
    Storage::put($old, 'old');
    Storage::put($new, 'new');
    touch(Storage::path($old), now()->subHours(InsightsPdfExports::RETENTION_HOURS + 1)->getTimestamp());
    touch(Storage::path($new), now()->getTimestamp());

    $this->artisan('insights:prune-pdfs')->assertSuccessful();

    Storage::assertMissing($old);
    Storage::assertExists($new);
});

test('the workspace PDF orders channels and their parts like the Performance table sort', function (string $direction, array $expected) {
    $facebook = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Facebook, 'username' => 'bravo']);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $facebook->id,
        'social_account_key' => $facebook->id,
        'platform' => Platform::Facebook,
        'network' => Platform::Facebook->network(),
        'provider_published_at' => CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-16',
        'reactions_count' => 90,
    ]);

    $data = app(BuildInsightsPdf::class)->forWorkspace(
        $this->workspace,
        $this->user,
        ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30', 'sort' => 'reactions', 'direction' => $direction],
        ['name' => null, 'sections' => ['performance', 'channels'], 'columns' => ['reactions']],
    );

    expect(array_map(fn (array $row): string => data_get($row, 'account.network'), data_get($data, 'performance.rows')))->toBe($expected)
        ->and(array_map(fn (array $part): string => data_get($part, 'account.network'), data_get($data, 'channels')))->toBe($expected);
})->with([
    'most reactions first' => ['desc', [Platform::Facebook->label(), Platform::Instagram->label()]],
    'fewest reactions first' => ['asc', [Platform::Instagram->label(), Platform::Facebook->label()]],
]);

test('a channel PDF only offers the sections of the channel page', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.channels.insights.pdf.store', $this->instagram), ['sections' => ['top_posts_reactions']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sections.0');
});

test('an export still rendering reports pending and cannot be downloaded yet', function () {
    $id = InsightsPdfExports::start($this->user, 'report.pdf');

    $this->actingAs($this->user)
        ->getJson(route('app.insights.pdf.show', $id))
        ->assertOk()
        ->assertJsonPath('status', InsightsPdfExports::PENDING)
        ->assertJsonPath('download_url', null);

    $this->actingAs($this->user)->get(route('app.insights.pdf.download', $id))->assertNotFound();
});

test('a channel PDF keeps the label filter of the page and counts the posts of the period', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($this->instagram)->published()->create(['user_id' => $this->user->id]);
    $post->labels()->attach($label);
    $tagged = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->instagram->id,
        'social_account_key' => $this->instagram->id,
        'post_id' => $post->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'excerpt' => 'Tagged campaign post',
        'provider_published_at' => CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $tagged->id, 'date' => '2026-09-18', 'reactions_count' => 5]);

    $this->actingAs($this->user)
        ->postJson(route('app.channels.insights.pdf.store', $this->instagram), [
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'labels' => [$label->id],
            'sections' => ['posts'],
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee(['Tagged campaign post', trans_choice('analytics.insights.export.pdf.posts_count', 1, ['count' => 1])]);
    Pdf::assertDontSee('Launch day recap');
});

test('the workspace PDF details at most 25 channels and says how many were left out', function () {
    foreach (range(1, BuildInsightsPdf::MAX_CHANNELS) as $index) {
        $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram, 'username' => "extra{$index}"]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'provider_published_at' => CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC'),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => '2026-09-10', 'reactions_count' => 1]);
    }

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30', 'sections' => ['channels']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee(__('analytics.insights.export.pdf.channels_note', ['shown' => BuildInsightsPdf::MAX_CHANNELS, 'total' => BuildInsightsPdf::MAX_CHANNELS + 1]));
});

test('a reconnected channel keeps its part, matched through its analytics key', function () {
    $previousKey = (string) Str::uuid();
    $reconnected = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Facebook, 'platform_user_id' => 'page-1']);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => null,
        'social_account_key' => $previousKey,
        'platform' => Platform::Facebook,
        'network' => Platform::Facebook->network(),
        'platform_user_id' => 'page-1',
        'date' => '2026-09-20',
        'followers_count' => 80,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => null,
        'social_account_key' => $previousKey,
        'platform' => Platform::Facebook,
        'network' => Platform::Facebook->network(),
        'platform_user_id' => 'page-1',
        'provider_published_at' => CarbonImmutable::parse('2026-09-12 12:00:00', 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => '2026-09-12', 'reactions_count' => 7]);

    $data = app(BuildInsightsPdf::class)->forWorkspace(
        $this->workspace,
        $this->user,
        ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30'],
        ['name' => null, 'sections' => ['channels'], 'columns' => []],
    );

    expect(array_map(fn (array $part): string => data_get($part, 'account.network'), data_get($data, 'channels')))
        ->toContain($reconnected->platform->label())
        ->and(data_get($data, 'channels_total'))->toBe(2);
});

test('only an admin can change the cover logo, while any member can export', function () {
    $member = User::factory()->create(['account_id' => $this->workspace->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member)
        ->post(route('app.insights.pdf.store'), ['sections' => ['summary'], 'logo' => UploadedFile::fake()->image('logo.png')])
        ->assertForbidden();
    $this->actingAs($member)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary'], 'remove_logo' => true])
        ->assertForbidden();
    $this->actingAs($member)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect($this->workspace->fresh()->getFirstMedia('report_logo'))->toBeNull();
});

test('the report logo wins over the workspace logo', function () {
    Storage::put('medias/workspace.png', UploadedFile::fake()->image('workspace.png', 30, 30)->getContent());
    $this->workspace->addMediaFromStoredPath('medias/workspace.png', 'workspace.png', 'image/png', 100, 'logo');
    Storage::put('medias/report.webp', UploadedFile::fake()->image('report.webp', 30, 30)->getContent());
    $this->workspace->addMediaFromStoredPath('medias/report.webp', 'report.webp', 'image/webp', 100, 'report_logo');

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee('src="data:image/webp;base64,');
    Pdf::assertDontSee('src="data:image/png;base64,');
});

test('a pruned PDF cannot be downloaded any more', function () {
    $id = $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->json('id');

    Storage::delete(InsightsPdfExports::path($id));

    $this->get(route('app.insights.pdf.download', $id))->assertNotFound();
});

test('a column the browser remembers but the PDF does not know is ignored', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['performance'], 'columns' => ['reactions', 'retired_metric']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee(__('analytics.channel.metrics.reactions.label'));
    Pdf::assertDontSee('retired_metric');
});

test('the cover shows when it was generated on the user clock', function (TimeFormat $clock, string $expected) {
    $this->user->update(['time_format' => $clock, 'timezone' => 'America/Sao_Paulo']);

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee($expected);
})->with([
    '12 hours' => [TimeFormat::TwelveHour, '9:00 AM'],
    '24 hours' => [TimeFormat::TwentyFourHour, '09:00'],
]);

test('asking for PDFs is rate limited', function () {
    foreach (range(1, 6) as $attempt) {
        $this->actingAs($this->user)
            ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
            ->assertStatus(Response::HTTP_ACCEPTED);
    }

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertTooManyRequests();
});

test('a channel post table cut at the top posts says how many were left out', function () {
    AnalyticsPublication::factory()
        ->count(ListChannelPublicationPerformance::TOP_POSTS)
        ->sequence(fn ($sequence): array => ['provider_published_at' => CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC')->addMinutes($sequence->index)])
        ->create([
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->instagram->id,
            'social_account_key' => $this->instagram->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
        ]);
    $total = ListChannelPublicationPerformance::TOP_POSTS + 1;

    $this->actingAs($this->user)
        ->postJson(route('app.channels.insights.pdf.store', $this->instagram), [
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'sections' => ['posts'],
        ])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee([
        trans_choice('analytics.insights.export.pdf.posts_count', ListChannelPublicationPerformance::TOP_POSTS, ['count' => ListChannelPublicationPerformance::TOP_POSTS]),
        __('analytics.insights.export.pdf.posts_note', ['shown' => ListChannelPublicationPerformance::TOP_POSTS, 'total' => $total]),
    ]);
});

test('the channel sort orders accented names like the page, not by bytes', function () {
    AnalyticsPublication::query()->where('social_account_id', $this->instagram->id)->update(['account_username' => 'zeta']);
    $facebook = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Facebook]);
    AnalyticsPublication::factory()->create([
        'account_username' => 'élan',
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $facebook->id,
        'social_account_key' => $facebook->id,
        'platform' => Platform::Facebook,
        'network' => Platform::Facebook->network(),
        'provider_published_at' => CarbonImmutable::parse('2026-09-16 12:00:00', 'UTC'),
    ]);

    $data = app(BuildInsightsPdf::class)->forWorkspace(
        $this->workspace,
        $this->user,
        ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30', 'sort' => 'channel', 'direction' => 'asc'],
        ['name' => null, 'sections' => ['performance'], 'columns' => ['reactions']],
    );

    expect(array_map(fn (array $row): string => data_get($row, 'account.network'), data_get($data, 'performance.rows')))
        ->toBe([Platform::Facebook->label(), Platform::Instagram->label()]);
});

test('starting an export answers with the cover logo it saved', function () {
    $this->actingAs($this->user)
        ->post(route('app.insights.pdf.store'), [
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'sections' => ['summary'],
            'logo' => UploadedFile::fake()->image('brand.png', 200, 200),
        ], ['Accept' => 'application/json'])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonPath('report_logo_url', $this->workspace->fresh()->getFirstMediaUrl('report_logo'));

    $this->actingAs($this->user)
        ->post(route('app.insights.pdf.store'), [
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'sections' => ['summary'],
            'remove_logo' => true,
        ], ['Accept' => 'application/json'])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonPath('report_logo_url', null);
});

test('a channel part leaves out what the report already shows', function () {
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->instagram->id,
        'social_account_key' => $this->instagram->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $this->instagram->platform_user_id,
        'date' => '2026-09-25',
        'followers_count' => 520,
    ]);

    $data = app(BuildInsightsPdf::class)->forWorkspace(
        $this->workspace,
        $this->user,
        ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30'],
        ['name' => null, 'sections' => ['top_posts_reactions', 'performance', 'channels'], 'columns' => ['reactions']],
    );
    $part = data_get($data, 'channels.0');
    $labels = array_column(data_get($part, 'summary'), 'label');

    expect($labels)->not->toContain(__('analytics.channel.metrics.reactions.label'))
        ->and($labels)->not->toContain(__('analytics.channel.metrics.net_followers.label'))
        ->and($labels)->toContain(__('analytics.channel.metrics.followers.label'))
        ->and(array_column(data_get($part, 'charts'), 'title'))->not->toContain(__('analytics.channel.series.net_followers'))
        ->and($part)->not->toHaveKey('top_posts')
        ->and(array_column(data_get($data, 'top_reactions'), 'excerpt'))->toContain('Launch day recap');
});

test('without the Performance table a channel part keeps its metrics', function () {
    $data = app(BuildInsightsPdf::class)->forWorkspace(
        $this->workspace,
        $this->user,
        ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30'],
        ['name' => null, 'sections' => ['channels'], 'columns' => ['reactions']],
    );
    $part = data_get($data, 'channels.0');

    expect(array_column(data_get($part, 'summary'), 'label'))->toContain(__('analytics.channel.metrics.reactions.label'));
});

test('a finished PDF is announced on its export channel, ready or failed', function () {
    Event::fake([InsightsPdfExportFinished::class]);
    $ready = InsightsPdfExports::start($this->user, 'report.pdf');

    GenerateInsightsPdf::dispatch($ready, $this->user->id, $this->workspace->id, null, ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30'], ['name' => null, 'sections' => ['summary'], 'columns' => []]);

    Event::assertDispatched(InsightsPdfExportFinished::class, fn (InsightsPdfExportFinished $event): bool => $event->exportId === $ready
        && $event->status === InsightsPdfExports::READY
        && $event->broadcastOn()[0]->name === "private-insights-pdf.{$ready}"
        && data_get($event->broadcastWith(), 'download_url') === route('app.insights.pdf.download', $ready));

    $this->mock(BuildInsightsPdf::class)->shouldReceive('forWorkspace')->andThrow(new RuntimeException('Chromium crashed'));
    $failed = InsightsPdfExports::start($this->user, 'report.pdf');

    expect(fn () => GenerateInsightsPdf::dispatch($failed, $this->user->id, $this->workspace->id, null, [], ['name' => null, 'sections' => ['summary'], 'columns' => []]))
        ->toThrow(RuntimeException::class);

    Event::assertDispatched(InsightsPdfExportFinished::class, fn (InsightsPdfExportFinished $event): bool => $event->exportId === $failed
        && $event->status === InsightsPdfExports::FAILED
        && data_get($event->broadcastWith(), 'download_url') === null);
});

test('only the user who asked for a PDF may listen on its channel', function () {
    $id = InsightsPdfExports::start($this->user, 'report.pdf');
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot('admin'));

    expect((new InsightsPdfChannel)->join($this->user, $id))->toBeTrue()
        ->and((new InsightsPdfChannel)->join($member, $id))->toBeFalse()
        ->and((new InsightsPdfChannel)->join($this->user, (string) Str::uuid7()))->toBeFalse();
});
