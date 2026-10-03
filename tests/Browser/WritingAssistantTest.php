<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response as AuthResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Poll from the page (never sleep()) until the element is laid out and, when
 * given, contains the expected text.
 */
function waitForWritingAssistantTestId(mixed $page, string $testId, string $text = ''): void
{
    $expected = json_encode($text);

    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                const element = document.querySelector('[data-testid="{$testId}"]');
                if (element?.getBoundingClientRect().height > 0
                    && (element.value ?? element.textContent ?? '').includes({$expected})) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * The composer opens as an animated dialog; a click that lands before the
 * animation settles is swallowed.
 */
function waitForWritingAssistantComposer(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
                if (sheet?.getAttribute('data-state') === 'open'
                    && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                    && document.querySelector('[data-testid="composer-add-account"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @param  array<int, Platform>  $platforms
 * @return array{0: User, 1: array<int, SocialAccount>}
 */
function writingAssistantSetup(array $platforms): array
{
    config()->set('trypost.self_hosted', true);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $accounts = array_map(
        fn (Platform $platform): SocialAccount => SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => $platform,
        ]),
        $platforms,
    );

    return [$user, $accounts];
}

/**
 * @param  array<int, SocialAccount>  $accounts
 */
function openWritingAssistant(User $user, array $accounts, string $content = ''): mixed
{
    test()->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForWritingAssistantComposer($page);
    if ($content !== '') {
        $page->fill('@composer-base-content', $content);
    }
    $page->click('@composer-add-account');
    foreach ($accounts as $account) {
        $page->click("@composer-account-option-{$account->id}");
    }
    $page->click('@composer-ai-assistant');
    waitForWritingAssistantTestId($page, 'writing-assistant-panel');

    return $page;
}

test('the assistant shows its disclaimer and disables rewrites without text', function () {
    [$user, $accounts] = writingAssistantSetup([Platform::LinkedIn]);

    openWritingAssistant($user, $accounts)
        ->assertVisible('@writing-assistant-panel')
        ->assertVisible('@writing-assistant-disclaimer')
        ->assertDisabled('@writing-assistant-mode-rephrase')
        ->assertNoJavaScriptErrors();
});

test('generate needs four words, regenerates and inserts after the existing text', function () {
    [$user, $accounts] = writingAssistantSetup([Platform::LinkedIn]);
    PostWritingAssistant::fake(['First idea', 'Second idea']);

    $page = openWritingAssistant($user, $accounts, 'Existing line')
        ->click('@writing-assistant-mode-generate')
        ->fill('@writing-assistant-prompt', 'new')
        ->assertDisabled('@writing-assistant-generate')
        ->assertVisible('@writing-assistant-prompt-hint')
        ->fill('@writing-assistant-prompt', 'new summer collection launch')
        ->assertEnabled('@writing-assistant-generate')
        ->click('@writing-assistant-generate');

    waitForWritingAssistantTestId($page, 'writing-assistant-suggestion', 'First idea');
    $page->assertSeeIn('@writing-assistant-suggestion', 'First idea')
        ->click('@writing-assistant-regenerate');

    waitForWritingAssistantTestId($page, 'writing-assistant-suggestion', 'Second idea');
    $page->assertSeeIn('@writing-assistant-suggestion', 'Second idea')
        ->click('@writing-assistant-insert')
        ->assertValue("@composer-caption-{$accounts[0]->id}", "Existing line\n\nSecond idea")
        ->assertNoJavaScriptErrors();
});

test('a rewrite replaces the text and offers no regenerate', function () {
    [$user, $accounts] = writingAssistantSetup([Platform::LinkedIn]);
    PostWritingAssistant::fake(['Hi everyone']);

    $page = openWritingAssistant($user, $accounts, 'Hello world')
        ->click('@writing-assistant-mode-rephrase');

    waitForWritingAssistantTestId($page, 'writing-assistant-replace');
    $page->assertMissing('@writing-assistant-regenerate')
        ->click('@writing-assistant-replace')
        ->assertValue("@composer-caption-{$accounts[0]->id}", 'Hi everyone')
        ->assertNoJavaScriptErrors();
});

test('a single X channel shows its limit and the shared step shows none', function () {
    [$user, $accounts] = writingAssistantSetup([Platform::X]);

    openWritingAssistant($user, $accounts)
        ->assertSeeIn('@writing-assistant-channel', '280')
        ->assertNoJavaScriptErrors();

    [$user, $accounts] = writingAssistantSetup([Platform::X, Platform::Instagram]);

    openWritingAssistant($user, $accounts)
        ->assertMissing('@writing-assistant-channel')
        ->assertNoJavaScriptErrors();
});

test('a premium X channel gets suggestions written for its own limit', function () {
    [$user, $accounts] = writingAssistantSetup([Platform::X]);
    $accounts[0]->update(['meta' => ['x_subscription_type' => 'Premium']]);
    $long = trim(str_repeat('Great news for everyone. ', 16));
    PostWritingAssistant::fake([$long]);

    $page = openWritingAssistant($user, $accounts)
        ->assertSeeIn('@writing-assistant-channel', '25')
        ->click('@writing-assistant-mode-generate')
        ->fill('@writing-assistant-prompt', 'announce our summer launch today')
        ->click('@writing-assistant-generate');

    waitForWritingAssistantTestId($page, 'writing-assistant-suggestion', 'Great news');
    expect(trim((string) $page->script("document.querySelector('[data-testid=\"writing-assistant-suggestion\"]').textContent")))->toBe($long);
    $page->assertNoJavaScriptErrors();
});

test('a denied AI gate shows the subscription message', function () {
    [$user, $accounts] = writingAssistantSetup([Platform::LinkedIn]);
    PostWritingAssistant::fake(['unused']);
    Gate::before(fn (User $user, string $ability): ?AuthResponse => $ability === 'useAi'
        ? AuthResponse::deny(__('billing.flash.subscription_required'))
        : null);

    $page = openWritingAssistant($user, $accounts)
        ->click('@writing-assistant-mode-generate')
        ->fill('@writing-assistant-prompt', 'new summer collection launch')
        ->click('@writing-assistant-generate');

    waitForWritingAssistantTestId($page, 'writing-assistant-error', __('billing.flash.subscription_required'));
    $page->assertSeeIn('@writing-assistant-error', __('billing.flash.subscription_required'))
        ->assertNoJavaScriptErrors();

    PostWritingAssistant::assertNeverPrompted();
});

test('switching channel after a suggestion resets the panel and never writes to the other channel', function () {
    [$user, [$linkedIn, $x]] = writingAssistantSetup([Platform::LinkedIn, Platform::X]);
    PostWritingAssistant::fake(['Shortened for LinkedIn']);

    test()->actingAs($user);
    $page = visit(route('app.posts.create'));
    waitForWritingAssistantComposer($page);
    $page->fill('@composer-base-content', 'Hello world')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$linkedIn->id}")
        ->click("@composer-account-option-{$x->id}");
    waitForWritingAssistantTestId($page, 'composer-customization');
    $page->click("@composer-account-{$linkedIn->id}")
        ->click('@composer-ai-assistant');
    waitForWritingAssistantTestId($page, 'writing-assistant-panel');
    $page->click('@writing-assistant-mode-shorten');

    waitForWritingAssistantTestId($page, 'writing-assistant-replace');
    $page->assertSeeIn('@writing-assistant-suggestion', 'Shortened for LinkedIn')
        ->click("@composer-account-{$x->id}");

    waitForWritingAssistantTestId($page, 'writing-assistant-mode-shorten');
    $page->assertVisible('@writing-assistant-mode-shorten')
        ->assertMissing('@writing-assistant-replace')
        ->assertMissing('@writing-assistant-suggestion')
        ->assertNoJavaScriptErrors();
});

test('a validation error shows the server message inline', function () {
    [$user, $accounts] = writingAssistantSetup([Platform::LinkedIn]);
    PostWritingAssistant::fake(['unused']);
    $tooLong = str_repeat('a ', intdiv(Post::CONTENT_MAX_LENGTH, 2) + 1);
    $message = __('validation.max.string', ['attribute' => 'current content', 'max' => Post::CONTENT_MAX_LENGTH]);

    $page = openWritingAssistant($user, $accounts, $tooLong)
        ->click('@writing-assistant-mode-rephrase');

    waitForWritingAssistantTestId($page, 'writing-assistant-error', $message);
    $page->assertSeeIn('@writing-assistant-error', $message)
        ->assertNoJavaScriptErrors();

    PostWritingAssistant::assertNeverPrompted();
});
