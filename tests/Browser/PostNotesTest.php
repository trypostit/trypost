<?php

declare(strict_types=1);

use App\Mail\PostNoteAdded;
use App\Mail\PostNoteMentioned;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostNoteReaction;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

test('a note mentions a teammate picked from the list and takes reactions', function () {
    Mail::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $teammate = User::factory()->create(['name' => 'Bia Lima']);
    $workspace->members()->attach($teammate->id, membershipPivot('member'));

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello team',
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));

    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-input');

    $page->assertScript('(() => { const field = document.querySelector("[data-testid=note-composer]").getBoundingClientRect(); const send = document.querySelector("[data-testid=note-send]").getBoundingClientRect(); return Math.abs((field.top + field.height / 2) - (send.top + send.height / 2)) <= 1 && send.right <= field.right; })()', true);

    $page->fill('@note-input', 'Please check the @image, @bi');
    waitForOpen($page, '@note-mention-list');
    waitForText($page, '@note-mention-option', 'Bia Lima');
    $page->assertScript('(() => { const list = document.querySelector("[data-testid=note-mention-list]").getBoundingClientRect(); return list.top >= 0 && list.bottom <= window.innerHeight; })()', true);
    $page->click('@note-mention-option');
    waitForGone($page, '@note-mention-list');
    $page->assertValue('@note-input', 'Please check the @image, @Bia Lima ');

    $page->click('@note-send');
    waitFor($page, '@note-mention');

    $page->assertSeeIn('@note-body', 'Please check the @image, @Bia Lima')
        ->assertSeeIn('@note-mention', '@Bia Lima');

    expect(PostNote::query()->where('post_id', $post->id)->value('body'))
        ->toBe("Please check the @image, @[Bia Lima](user:{$teammate->id})");

    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($teammate->email));
    Mail::assertNotQueued(PostNoteAdded::class);

    $page->click('@note-react');
    waitForOpen($page, '[data-slot=popover-content]:has(@emoji-picker-option)');
    $page->click('@emoji-picker-option');
    waitFor($page, '[data-testid^=note-reaction-]');
    waitForState($page, '[data-testid^=note-reaction-]', "element.getAttribute('aria-pressed') === 'true' && element.textContent.includes('1')");

    expect(PostNoteReaction::query()->where('user_id', $user->id)->count())->toBe(1);

    $page->click('[data-testid^=note-reaction-]');
    waitForGone($page, '[data-testid^=note-reaction-]');

    $page->assertNoJavaScriptErrors();

    expect(PostNoteReaction::query()->count())->toBe(0);
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

    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@notes-empty');

    $page->assertVisible('@notes-empty')
        ->assertMissing("@post-notes-count-{$post->id}")
        ->assertDisabled('@note-send');

    $page->fill('@note-input', '   ');
    $page->assertDisabled('@note-send');

    $page->fill('@note-input', 'First line');
    $page->assertEnabled('@note-send');

    $page->keys('@note-input', ['Shift+Enter']);

    expect($page->script("document.querySelector('[data-testid=\"note-input\"]').value"))->toBe("First line\n");
    expect(PostNote::query()->where('post_id', $post->id)->exists())->toBeFalse();

    $page->keys('@note-input', ['Enter']);
    waitFor($page, '@note-body');
    waitForText($page, "@post-notes-count-{$post->id}", '1');

    $page->assertSeeIn('@note-body', 'First line')
        ->assertValue('@note-input', '')
        ->assertDisabled('@note-send')
        ->assertSeeIn("@post-notes-count-{$post->id}", '1')
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
    $createdAt = now()->subMonth()->setTime(10, 0);
    PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts', 'tz' => 'America/Sao_Paulo']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-created-at');

    expect($page->script('document.querySelector(\'[data-testid="note-created-at"]\').getAttribute("title")'))
        ->toBe($createdAt->setTimezone('America/Sao_Paulo')->format('F j, Y g:i A'));
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
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-created-at');

    expect(trim((string) $page->script('document.querySelector(\'[data-testid="note-created-at"]\').textContent')))
        ->toBe('an hour ago');
    $page->assertNoJavaScriptErrors();
});

test('the notes toggle says whether it shows or hides the notes', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");

    $page->hover("@post-notes-trigger-{$post->id}");
    waitFor($page, "@post-notes-tooltip-{$post->id}");
    $page->assertSeeIn("@post-notes-tooltip-{$post->id}", __('notes.show'));

    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-input');
    $page->hover('@note-input')->hover("@post-notes-trigger-{$post->id}");
    waitFor($page, "@post-notes-tooltip-{$post->id}");

    $page->assertSeeIn("@post-notes-tooltip-{$post->id}", __('notes.hide'))
        ->assertNoJavaScriptErrors();
});

test('the notes count follows the thread when it opens and when a note is deleted', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => 'First']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-count-{$post->id}");
    $page->assertSeeIn("@post-notes-count-{$post->id}", '1');

    PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => 'Second']);
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-body');
    waitForCount($page, '@note-item', 2);
    waitForText($page, "@post-notes-count-{$post->id}", '2');
    $page->assertSeeIn("@post-notes-count-{$post->id}", '2');

    $page->hover('@note-item')->click('@note-actions');
    waitFor($page, '@note-delete');
    $page->click('@note-delete');
    waitForCount($page, '@note-item', 1);
    waitForText($page, "@post-notes-count-{$post->id}", '1');

    $page->assertSeeIn("@post-notes-count-{$post->id}", '1')->assertNoJavaScriptErrors();
});

test('notes open, grow, send, edit and fit the card on every tab, on desktop and on a phone', function (string $tab, string $state, int $width) {
    Mail::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->{$state}()->create(['user_id' => $user->id, 'content' => 'hello team']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => $tab]))->resize($width, 900);
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-input');

    $singleLine = $page->script("document.querySelector('[data-testid=\"note-input\"]').getBoundingClientRect().height");
    $page->fill('@note-input', "First line\nSecond line\nThird line");
    $grown = $page->script("document.querySelector('[data-testid=\"note-input\"]').getBoundingClientRect().height");
    expect($grown)->toBeGreaterThan($singleLine);

    $page->keys('@note-input', ['Enter']);
    waitFor($page, '@note-body');
    waitForText($page, "@post-notes-count-{$post->id}", '1');
    $page->assertSeeIn("@post-notes-count-{$post->id}", '1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);

    $page->hover('@note-item')->click('@note-actions');
    waitFor($page, '@note-edit');
    $page->click('@note-edit');
    waitFor($page, '@note-edit-input');
    $page->fill('@note-edit-input', 'Edited note')
        ->click('@note-edit-save');
    waitForGone($page, '@note-edit-input');
    waitForText($page, '@note-body', 'Edited note');

    $page->assertSeeIn('@note-body', 'Edited note')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
    expect(PostNote::query()->where('post_id', $post->id)->sole()->body)->toBe('Edited note');
})->with([
    'drafts' => ['drafts', 'draft'],
    'queue' => ['queue', 'scheduled'],
    'approvals' => ['approvals', 'pendingApproval'],
    'sent' => ['sent', 'published'],
])->with([
    'desktop' => [1280],
    'phone' => [390],
]);
