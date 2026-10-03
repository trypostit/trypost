<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

function waitForComposerPreviewTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
                if (sheet?.getAttribute('data-state') === 'open'
                    && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                    && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @return array{0: User, 1: array<string, SocialAccount>}
 */
function composerPreviewWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $accounts = collect(['x', 'tiktok', 'instagram', 'youtube'])
        ->mapWithKeys(fn (string $platform): array => [
            $platform => SocialAccount::factory()->{$platform}()->create([
                'workspace_id' => $workspace->id,
                'username' => 'paulocastellano',
            ]),
        ])
        ->all();

    return [$user, $accounts];
}

test('the preview panel lists one labelled card per network and renders each network chrome', function () {
    Http::fake();
    [$user] = composerPreviewWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerPreviewTestId($page, 'composer-add-account');

    $page->click('@composer-add-account')
        ->click('@composer-select-all')
        ->fill('@composer-base-content', 'Testing the preview https://trypost.it #hashtags');
    waitForComposerPreviewTestId($page, 'youtube-preview-subscribe');

    $page->assertSeeIn('@composer-preview-title', 'Post previews')
        ->assertPresent('@composer-preview-info');

    expect($page->script('[...document.querySelectorAll("[data-testid=composer-preview-label]")].map((label) => label.textContent.trim())'))
        ->toEqualCanonicalizing(['X', 'TikTok', 'Instagram', 'YouTube'])
        ->and($page->script('document.querySelectorAll("[data-testid=composer-preview-frame]").length'))->toBe(4);

    expect($page->script(<<<'JS'
        (() => {
            const frames = [...document.querySelectorAll('[data-testid="composer-preview-frame"]')];
            const column = document.querySelector('[data-testid="composer-previews-scroll"]').getBoundingClientRect();
            const style = getComputedStyle(frames[0]);

            return {
                fillColumn: frames.every((frame) => Math.abs(frame.getBoundingClientRect().width - (column.width - 64)) < 2),
                radius: style.borderTopLeftRadius,
                shadow: style.boxShadow,
            };
        })()
    JS))->toEqual(['fillColumn' => true, 'radius' => '8px', 'shadow' => 'none']);

    expect($page->script('document.querySelectorAll("[data-testid=x-preview] [data-testid=preview-actions] svg").length'))->toBe(6)
        ->and($page->script('[...document.querySelectorAll("[data-testid=x-preview-content] .underline")].map((token) => token.textContent)'))
        ->toBe(['https://trypost.it', '#hashtags']);

    $page->assertSeeIn('@tiktok-preview-sound', 'original sound - paulocastellano')
        ->assertSeeIn('@youtube-preview-subscribe', 'Subscribe')
        ->assertSeeIn('@instagram-preview-username', 'paulocastellano');

    expect($page->script('document.querySelectorAll("[data-testid=youtube-preview] [data-testid=preview-rail-action]").length'))->toBe(4);

    $page->click('@composer-next');
    waitForComposerPreviewTestId($page, 'composer-customization');

    expect($page->script('document.querySelector("[data-testid=composer-preview-title]").textContent.trim()'))
        ->toMatch('/^(X|TikTok|Instagram|YouTube) preview$/')
        ->and($page->script('document.querySelectorAll("[data-testid=composer-preview-frame]").length'))->toBe(1)
        ->and($page->script('document.querySelectorAll("[data-testid=composer-preview-label]").length'))->toBe(0);

    $page->assertNoJavaScriptErrors();
});
