<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Automation;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use App\Models\Workspace;

/**
 * Guards against horizontal overflow on mobile across the layout variants
 * (AuthLayout, default AppLayout, full-width editor, automations detail).
 * Reproduces the AuthSplitLayout long-name overflow that a fixed grid column
 * caused. Returns the widest offending element on failure for quick triage.
 */
function assertNoHorizontalOverflow(mixed $page, string $label): void
{
    $result = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 80; i++) {
                if (document.querySelector('h1, h2, main, [data-sidebar="trigger"]')) break;
                await new Promise((r) => setTimeout(r, 50));
            }
            await new Promise((r) => setTimeout(r, 400));
            const vw = window.innerWidth;
            let worst = null;
            document.querySelectorAll('*').forEach((el) => {
                const r = el.getBoundingClientRect();
                if (r.right > vw + 2 && (!worst || r.right > worst.right)) {
                    worst = { right: Math.round(r.right), tag: el.tagName.toLowerCase(), cls: (el.getAttribute('class') || '').slice(0, 80) };
                }
            });
            return { sw: document.documentElement.scrollWidth, iw: vw, worst };
        })()
    JS);

    $sw = (int) ($result['sw'] ?? 0);
    $iw = (int) ($result['iw'] ?? 375);
    $worst = $result['worst'] ?? null;
    $detail = $worst ? " widest: <{$worst['tag']} class=\"{$worst['cls']}\"> right={$worst['right']}" : '';

    expect($sw - $iw)->toBeLessThanOrEqual(2, "{$label} overflows: scrollWidth {$sw} > innerWidth {$iw}.{$detail}");
}

test('key pages do not overflow horizontally on a phone', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        // A long name previously stretched the AuthSplitLayout grid column past the viewport.
        'name' => 'A Really Very Extremely Long Workspace Name That Should Truncate Instead Of Overflowing',
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Launching our new mobile editor today!',
    ]);
    PostPlatform::factory()->count(2)->create(['post_id' => $post->id]);
    $automation = Automation::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $pages = [
        'workspaces (AuthLayout)' => route('app.workspaces.index'),
        'posts index (default)' => route('app.posts.index'),
        'post editor (full-width)' => route('app.posts.edit', $post),
        'calendar (full-width)' => route('app.calendar'),
        'settings (tabs)' => route('app.api-keys.index'),
        'automation metrics (detail)' => route('app.automations.metrics', $automation),
    ];

    foreach ($pages as $label => $url) {
        assertNoHorizontalOverflow(visit($url)->resize(375, 812), $label);
    }
});
