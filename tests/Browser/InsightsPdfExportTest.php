<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\GenerateInsightsPdf;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Symfony\Component\HttpFoundation\Response;

function insightsPdfBrowserUser(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id, 'name' => 'Acme Studio']);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $account->platform_user_id,
        'date' => now('UTC')->subDays(3)->toDateString(),
        'followers_count' => 120,
    ]);

    return $user->fresh();
}

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Pdf::fake();
    Storage::fake();
    Http::fake(['*' => Http::response('', Response::HTTP_NOT_FOUND)]);
});

test('the PDF export asks for the report name and sections, then downloads the file', function () {
    $this->actingAs(insightsPdfBrowserUser());

    $page = visit(route('app.insights'));
    waitFor($page, '@insights-export');
    $page->click('@insights-export');
    waitFor($page, '@insights-export-pdf');
    $page->click('@insights-export-pdf');
    waitFor($page, '@insights-pdf-dialog');

    $page->assertAttribute('@insights-pdf-name', 'placeholder', 'Acme Studio')
        ->assertAttribute('@insights-pdf-section-summary', 'data-state', 'checked')
        ->assertAttribute('@insights-pdf-section-posts_over_time', 'data-state', 'checked')
        ->assertMissing('@insights-pdf-section-metrics')
        ->type('@insights-pdf-name', 'September report')
        ->click('@insights-pdf-section-posts_over_time')
        ->click('@insights-pdf-create');

    waitForGone($page, '@insights-pdf-dialog');

    Pdf::assertSee(['"title":"September report"', '"sections":["summary","top_posts_reactions","top_posts_comments","performance","followers"]']);
    $page->assertNoJavaScriptErrors();
});

test('the PDF export needs at least one section', function () {
    $this->actingAs(insightsPdfBrowserUser());

    $page = visit(route('app.insights'));
    waitFor($page, '@insights-export');
    $page->click('@insights-export');
    waitFor($page, '@insights-export-pdf');
    $page->click('@insights-export-pdf');
    waitFor($page, '@insights-pdf-dialog');

    foreach (['summary', 'top_posts_reactions', 'top_posts_comments', 'performance', 'followers', 'posts_over_time'] as $section) {
        $page->click("@insights-pdf-section-{$section}");
    }

    $page->click('@insights-pdf-create');
    waitForText($page, '@insights-pdf-dialog', __('analytics.insights.export.pdf.sections_required'));

    $page->assertPresent('@insights-pdf-dialog')
        ->assertNoJavaScriptErrors();
});

test('a channel PDF offers the channel page sections and starts the export', function () {
    $user = insightsPdfBrowserUser();
    $account = $user->currentWorkspace->socialAccounts()->firstOrFail();
    $this->actingAs($user);

    $page = visit(route('app.channels.insights', $account));
    waitFor($page, '@insights-export');
    $page->click('@insights-export');
    waitFor($page, '@insights-export-pdf');
    $page->click('@insights-export-pdf');
    waitFor($page, '@insights-pdf-dialog');

    $page->assertAttribute('@insights-pdf-section-metrics', 'data-state', 'checked')
        ->assertAttribute('@insights-pdf-section-posts', 'data-state', 'checked')
        ->assertMissing('@insights-pdf-section-performance')
        ->click('@insights-pdf-create');

    waitForGone($page, '@insights-pdf-dialog');

    Pdf::assertSee('"component":"channels\/insights\/Print"');
    $page->assertNoJavaScriptErrors();
});

test('closing the dialog while the PDF is prepared keeps the export going', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class, GenerateInsightsPdf::class]);
    $this->actingAs(insightsPdfBrowserUser());

    $page = visit(route('app.insights'));
    waitFor($page, '@insights-export');
    $page->click('@insights-export');
    waitFor($page, '@insights-export-pdf');
    $page->click('@insights-export-pdf');
    waitFor($page, '@insights-pdf-dialog');
    $page->click('@insights-pdf-create');
    waitFor($page, '@insights-pdf-preparing');

    $page->click('@insights-pdf-close');
    waitForGone($page, '@insights-pdf-dialog');
    $page->click('@insights-export');
    waitFor($page, '@insights-export-pdf');
    $page->click('@insights-export-pdf');
    waitFor($page, '@insights-pdf-preparing');
    $page->assertPresent('@insights-pdf-preparing')
        ->assertMissing('@insights-pdf-create');

    $exportId = '';
    Queue::assertPushed(GenerateInsightsPdf::class, function (GenerateInsightsPdf $job) use (&$exportId): bool {
        $exportId = $job->exportId;

        return true;
    });
    Storage::put(InsightsPdfExports::path($exportId), '%PDF-1.4');
    InsightsPdfExports::markReady($exportId);

    waitForGone($page, '@insights-pdf-dialog');
    $page->assertMissing('@insights-pdf-dialog')
        ->assertNoJavaScriptErrors();
});
