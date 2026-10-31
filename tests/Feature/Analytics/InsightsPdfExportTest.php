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
use Illuminate\Http\Client\Factory;
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

/**
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function workspacePrintProps(array $options = [], array $query = []): array
{
    return app(BuildInsightsPdf::class)->forWorkspace(
        test()->workspace,
        test()->user,
        ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30', ...$query],
        ['name' => null, 'sections' => ['summary'], 'columns' => [], ...$options],
    )['props'];
}

/**
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function channelPrintProps(array $options = [], array $query = []): array
{
    return app(BuildInsightsPdf::class)->forChannel(
        test()->instagram,
        test()->user,
        ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30', ...$query],
        ['name' => null, 'sections' => ['summary'], 'columns' => [], ...$options],
    )['props'];
}

test('a workspace PDF prints the Insights print page with the chosen sections and downloads once ready', function () {
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

    Pdf::assertSee([
        '"component":"insights\/Print"',
        '"title":"September report"',
        '"sections":["summary","top_posts_reactions","performance"]',
        'Launch day recap',
    ]);

    $this->getJson(route('app.insights.pdf.show', $id))
        ->assertOk()
        ->assertJsonPath('status', InsightsPdfExports::READY)
        ->assertJsonPath('download_url', route('app.insights.pdf.download', $id));

    $this->get(route('app.insights.pdf.download', $id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('trypost-insights-2026-09-30.pdf');
});

test('a PDF is printed in the language and direction of the user who asked', function (Locale $locale) {
    $this->user->update(['locale' => $locale]);

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    Pdf::assertSee([
        "<html lang=\"{$locale->value}\" dir=\"{$locale->direction()}\"",
        "\"locale\":\"{$locale->value}\"",
    ]);
})->with(Locale::cases());

test('a PDF without a report name is titled with the workspace name', function () {
    expect(workspacePrintProps())->toMatchArray(['title' => 'Acme Studio']);
});

test('the cover uses the workspace logo until a report logo is uploaded', function () {
    expect(workspacePrintProps()['logoUrl'])->toBeNull();

    $this->workspace->addMediaFromStoredPath(
        tap('medias/workspace-logo.png', fn (string $path) => Storage::put($path, UploadedFile::fake()->image('logo.png', 40, 40)->getContent())),
        'logo.png',
        'image/png',
        100,
        'logo',
    );

    expect(workspacePrintProps()['logoUrl'])->toStartWith('data:image/png;base64,');
});

test('the report logo wins over the workspace logo', function () {
    Storage::put('medias/workspace.png', UploadedFile::fake()->image('workspace.png', 30, 30)->getContent());
    $this->workspace->addMediaFromStoredPath('medias/workspace.png', 'workspace.png', 'image/png', 100, 'logo');
    Storage::put('medias/report.webp', UploadedFile::fake()->image('report.webp', 30, 30)->getContent());
    $this->workspace->addMediaFromStoredPath('medias/report.webp', 'report.webp', 'image/webp', 100, 'report_logo');

    expect(workspacePrintProps()['logoUrl'])->toStartWith('data:image/webp;base64,');
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
    Pdf::assertSee('"logoUrl":"data:image\/png;base64,');

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect($this->workspace->fresh()->getFirstMedia('report_logo')?->id)->toBe($logo->id);

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary'], 'remove_logo' => true])
        ->assertStatus(Response::HTTP_ACCEPTED);

    expect($this->workspace->fresh()->getFirstMedia('report_logo'))->toBeNull();
});

test('starting an export answers with the cover logo it saved', function () {
    $this->actingAs($this->user)
        ->post(route('app.insights.pdf.store'), [
            'sections' => ['summary'],
            'logo' => UploadedFile::fake()->image('brand.png', 200, 200),
        ], ['Accept' => 'application/json'])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonPath('report_logo_url', $this->workspace->fresh()->getFirstMediaUrl('report_logo'));

    $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary'], 'remove_logo' => true])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonPath('report_logo_url', null);
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

test('the Performance table prints the page columns, at most six, and ignores unknown ones', function () {
    expect(workspacePrintProps(['columns' => ['posts', 'reactions', 'comments', 'engagement_rate', 'reposts', 'impressions', 'clicks', 'views']])['columns'])
        ->toHaveCount(BuildInsightsPdf::MAX_COLUMNS)
        ->not->toContain('clicks')
        ->and(workspacePrintProps(['columns' => ['reactions', 'retired_metric']])['columns'])->toBe(['reactions']);
});

test('the Performance table keeps the sort of the page', function () {
    expect(workspacePrintProps(query: ['sort' => 'channel', 'direction' => 'asc']))
        ->toMatchArray(['sort' => 'channel', 'direction' => 'asc']);
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

test('a channel PDF prints the channel print page with its posts and metrics', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.channels.insights.pdf.store', $this->instagram), [
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'sections' => ['summary', 'posts', 'metrics'],
        ])
        ->assertStatus(Response::HTTP_ACCEPTED)
        ->assertJsonPath('status', InsightsPdfExports::READY);

    Pdf::assertSee(['"component":"channels\/insights\/Print"', 'Launch day recap', '"metricSeries":']);
});

test('a channel PDF only offers the sections of the channel page', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.channels.insights.pdf.store', $this->instagram), ['sections' => ['top_posts_reactions']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sections.0');
});

test('a channel PDF keeps the label filter of the page', function () {
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

    $excerpts = collect(channelPrintProps(query: ['labels' => [$label->id]])['publications']->items())->pluck('excerpt');

    expect($excerpts->all())->toBe(['Tagged campaign post']);
});

test('a channel post table prints the whole ranking on one page', function () {
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

    $publications = channelPrintProps()['publications'];

    expect($publications->count())->toBe(ListChannelPublicationPerformance::TOP_POSTS)
        ->and($publications->lastPage())->toBe(1);
});

test('a channel post table prints the page columns, at most four, else the sortable ones', function () {
    $available = channelPrintProps()['availableMetrics'];
    $columns = channelPrintProps(['columns' => [...$available, 'retired_metric']])['columns'];

    expect($columns)->toHaveCount(min(4, count($available)))
        ->and(array_diff($columns, $available))->toBe([])
        ->and(channelPrintProps()['columns'])->not->toBeEmpty();
});

test('post thumbnails are shrunk to the size they print at', function () {
    $image = 'https://cdn.example.com/medias/photo.png';
    Http::swap(new Factory);
    Http::fake([$image => Http::response(UploadedFile::fake()->image('photo.png', 1200, 1500)->getContent(), Response::HTTP_OK, ['Content-Type' => 'image/png'])]);
    $post = Post::factory()->forAccount($this->instagram)->published()->create([
        'user_id' => $this->user->id,
        'media' => [['id' => (string) Str::uuid(), 'path' => 'medias/photo.png', 'url' => $image, 'type' => 'image', 'mime_type' => 'image/png']],
    ]);
    AnalyticsPublication::query()->where('social_account_id', $this->instagram->id)->update(['post_id' => $post->id]);

    $row = collect(channelPrintProps()['publications']->items())->firstWhere('excerpt', 'Launch day recap');

    expect($row['thumbnail_url'])->toStartWith('data:image/jpeg;base64,');
    Http::assertSent(fn ($request): bool => $request->url() === $image);
});

test('the cover shows when it was generated on the user clock', function (TimeFormat $clock, string $expected) {
    $this->user->update(['time_format' => $clock, 'timezone' => 'America/Sao_Paulo']);

    expect(workspacePrintProps()['generatedAt'])->toContain($expected);
})->with([
    '12 hours' => [TimeFormat::TwelveHour, '9:00 AM'],
    '24 hours' => [TimeFormat::TwentyFourHour, '09:00'],
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

test('an export still rendering reports pending and cannot be downloaded yet', function () {
    $id = InsightsPdfExports::start($this->user, 'report.pdf');

    $this->actingAs($this->user)
        ->getJson(route('app.insights.pdf.show', $id))
        ->assertOk()
        ->assertJsonPath('status', InsightsPdfExports::PENDING)
        ->assertJsonPath('download_url', null);

    $this->actingAs($this->user)->get(route('app.insights.pdf.download', $id))->assertNotFound();
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

test('a pruned PDF cannot be downloaded any more', function () {
    $id = $this->actingAs($this->user)
        ->postJson(route('app.insights.pdf.store'), ['sections' => ['summary']])
        ->json('id');

    Storage::delete(InsightsPdfExports::path($id));

    $this->get(route('app.insights.pdf.download', $id))->assertNotFound();
});

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
