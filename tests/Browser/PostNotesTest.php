<?php

declare(strict_types=1);

use App\Mail\PostNoteAdded;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

/**
 * Poll browser-side until the testid element has laid out (width/height > 0).
 * These Pest browser assertions do not auto-wait, so settle async UI first.
 */
function waitForPostNotesTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('adding a note from the popover shows it and emails the other members', function () {
    Mail::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate->id, membershipPivot('member'));

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello team',
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));

    waitForPostNotesTestId($page, "post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForPostNotesTestId($page, 'note-input');

    $page->assertScript('Math.round(document.querySelector("[data-testid=note-input]").getBoundingClientRect().height) === Math.round(document.querySelector("[data-testid=note-send]").getBoundingClientRect().height)', true);

    $page->fill('@note-input', 'Please check the @image before Friday');
    $page->click('@note-send');
    waitForPostNotesTestId($page, 'note-body');

    $page->assertSeeIn('@note-body', 'Please check the @image before Friday')
        ->assertVisible('@note-actions')
        ->assertMissing('@note-react')
        ->assertMissing('@note-reply')
        ->assertNoJavaScriptErrors();

    expect(PostNote::query()->where('post_id', $post->id)->value('body'))
        ->toBe('Please check the @image before Friday');

    Mail::assertQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($teammate->email));
    Mail::assertNotQueued(PostNoteAdded::class, fn (PostNoteAdded $mail) => $mail->hasTo($user->email));
});

test('the note composer enables send only with text and sends on Enter but not Shift+Enter', function () {
    Mail::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello team',
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));

    waitForPostNotesTestId($page, "post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForPostNotesTestId($page, 'notes-empty');

    $page->assertVisible('@notes-empty')
        ->assertMissing('@post-notes-count')
        ->assertDisabled('@note-send');

    $page->fill('@note-input', '   ');
    $page->assertDisabled('@note-send');

    $page->fill('@note-input', 'First line');
    $page->assertEnabled('@note-send');

    $page->keys('@note-input', ['Shift+Enter']);

    expect($page->script("document.querySelector('[data-testid=\"note-input\"]').value"))->toBe("First line\n");
    expect(PostNote::query()->where('post_id', $post->id)->exists())->toBeFalse();

    $page->keys('@note-input', ['Enter']);
    waitForPostNotesTestId($page, 'note-body');

    $page->assertSeeIn('@note-body', 'First line')
        ->assertValue('@note-input', '')
        ->assertDisabled('@note-send')
        ->assertSeeIn('@post-notes-count', '1')
        ->assertVisible("@post-notes-filled-icon-{$post->id}")
        ->assertNoJavaScriptErrors();

    expect(PostNote::query()->where('post_id', $post->id)->value('body'))->toBe('First line');
});

test('a note timestamp follows the display zone of the list', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'zone check']);
    PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'created_at' => '2026-09-20 10:00:00',
        'updated_at' => '2026-09-20 10:00:00',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts', 'tz' => 'America/Sao_Paulo']));
    waitForPostNotesTestId($page, "post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForPostNotesTestId($page, 'note-created-at');

    expect($page->script('document.querySelector(\'[data-testid="note-created-at"]\').getAttribute("title")'))
        ->toBe('September 20, 2026 7:00 AM');
    $page->assertNoJavaScriptErrors();
});

test('a note created an hour ago reads as past for a user whose zone is not the browser zone', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'relative check']);
    PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitForPostNotesTestId($page, "post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForPostNotesTestId($page, 'note-created-at');

    expect(trim((string) $page->script('document.querySelector(\'[data-testid="note-created-at"]\').textContent')))
        ->toBe('an hour ago');
    $page->assertNoJavaScriptErrors();
});
