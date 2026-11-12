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
use Illuminate\Http\UploadedFile;
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

test('the mention list works from the keyboard, says when nobody matches, an edit keeps the mentions and Esc cancels it', function () {
    Mail::fake();

    $user = User::factory()->create(['name' => 'Paulo Castellano']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $ana = User::factory()->create(['name' => 'Ana Souza']);
    $bia = User::factory()->create(['name' => 'Bia Lima']);
    $workspace->members()->attach($ana->id, membershipPivot('member'));
    $workspace->members()->attach($bia->id, membershipPivot('member'));

    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));

    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-input');

    $page->fill('@note-input', '@');
    waitForOpen($page, '@note-mention-list');
    waitForCount($page, '@note-mention-option', 2);
    waitForState($page, '@note-input', "element.getAttribute('aria-expanded') === 'true'");
    $page->keys('@note-input', ['ArrowDown', 'Enter']);
    waitForGone($page, '@note-mention-list');
    $page->assertValue('@note-input', '@Bia Lima ');

    $page->append('@note-input', 'hi @zzz');
    waitForText($page, '@note-mention-list', 'No teammate matches');
    $page->keys('@note-input', 'Escape');
    waitForGone($page, '@note-mention-list');
    $page->assertValue('@note-input', '@Bia Lima hi @zzz')
        ->assertVisible('@note-input');

    $page->keys('@note-input', 'Enter');
    waitFor($page, '@note-mention');

    $note = PostNote::query()->where('post_id', $post->id)->sole();
    expect($note->body)->toBe("@[Bia Lima](user:{$bia->id}) hi @zzz");

    $page->click('@note-actions');
    $page->click('@note-edit');
    waitFor($page, '@note-edit-input');
    $page->assertValue('@note-edit-input', '@Bia Lima hi @zzz');

    $page->append('@note-edit-input', ' and @an');
    waitForText($page, '@note-mention-option', 'Ana Souza');
    $page->keys('@note-edit-input', 'Tab');
    waitForGone($page, '@note-mention-list');
    $page->click('@note-edit-save');
    waitForCount($page, '@note-mention', 2);

    $page->click('@note-actions');
    $page->click('@note-edit');
    waitFor($page, '@note-edit-input');
    $page->append('@note-edit-input', ' discarded');
    $page->keys('@note-edit-input', 'Escape');
    waitForGone($page, '@note-edit-input');

    $page->assertNoJavaScriptErrors();

    expect($note->fresh()->body)->toBe("@[Bia Lima](user:{$bia->id}) hi @zzz and @[Ana Souza](user:{$ana->id})");
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($ana->email));
});

test('hovering a mention shows who it is, yourself included: photo, name and their local time', function () {
    $user = User::factory()->create(['name' => 'Paulo Castellano', 'time_format' => '24h']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $admin = workspaceMember($workspace, 'admin', ['name' => 'Ana Souza', 'timezone' => 'Asia/Tokyo']);
    $member = workspaceMember($workspace, 'member', ['name' => 'Bia Lima', 'timezone' => 'America/Sao_Paulo']);
    $formerMember = User::factory()->create(['name' => 'Caio Former']);
    $admin->addMedia(UploadedFile::fake()->image('avatar.jpg', 64, 64), 'avatar');
    $user->addMedia(UploadedFile::fake()->image('me.jpg', 64, 64), 'avatar');
    $photoUrl = json_encode($admin->fresh()->photo_url);
    $myPhotoUrl = json_encode($user->fresh()->photo_url);

    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => "@[Ana Souza](user:{$admin->id}) @[Bia Lima](user:{$member->id}) @[Caio Former](user:{$formerMember->id}) @[Paulo Castellano](user:{$user->id})",
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));

    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForOpen($page, "@post-notes-section-{$post->id}");
    waitForCount($page, '@note-mention', 4);

    $tokyoTimes = json_encode([now('Asia/Tokyo')->format('H:i'), now('Asia/Tokyo')->addMinute()->format('H:i')]);

    $page->hover('[data-testid=note-mention]:nth-child(1)');
    waitForOpen($page, '@note-mention-card');
    waitFor($page, '@note-mention-card-time');
    waitForState($page, '@note-mention-card img', "element.getAttribute('src') === {$photoUrl}");
    $page->assertSeeIn('@note-mention-card-name', 'Ana Souza');
    waitForCondition($page, "{$tokyoTimes}.some((time) => document.querySelector('[data-testid=note-mention-card-time]').textContent.includes(time + ' local time'))");

    $page->hover('@note-body');
    waitForGone($page, '@note-mention-card');
    $page->hover('[data-testid=note-mention]:nth-child(2)');
    waitForText($page, '@note-mention-card-name', 'Bia Lima');
    waitFor($page, '@note-mention-card-time');
    $page->assertMissing('@note-mention-card img');

    $page->hover('@note-body');
    waitForGone($page, '@note-mention-card');
    $page->hover('[data-testid=note-mention]:nth-child(3)');
    waitForText($page, '@note-mention-card-name', 'Caio Former');
    $page->assertMissing('@note-mention-card-time');

    $page->hover('@note-body');
    waitForGone($page, '@note-mention-card');
    $page->hover('[data-testid=note-mention]:nth-child(4)');
    waitForText($page, '@note-mention-card-name', 'Paulo Castellano');
    waitForState($page, '@note-mention-card img', "element.getAttribute('src') === {$myPhotoUrl}");
    $page->assertVisible('@note-mention-card-time')
        ->assertNoJavaScriptErrors();
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
