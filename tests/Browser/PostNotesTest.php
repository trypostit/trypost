<?php

declare(strict_types=1);

use App\Enums\User\Locale;
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
use Illuminate\Support\Str;

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
    waitForEditorText($page, '@note-input', 'Please check the @image, @Bia Lima');

    $page->click('@note-send');
    waitFor($page, '@note-mention');

    waitForText($page, '@note-body', 'Please check the @image, @Bia Lima');
    waitForText($page, '@note-mention', '@Bia Lima');

    expect(PostNote::query()->where('post_id', $post->id)->value('body'))
        ->toBe("Please check the @image, @[user:{$teammate->id}]");

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

    $page->hover('@note-input');
    $page->fill('@note-input', '@');
    waitForOpen($page, '@note-mention-list');
    waitForCount($page, '@note-mention-option', 2);
    waitForState($page, '@note-input', "element.getAttribute('aria-expanded') === 'true'");
    $page->keys('@note-input', ['ArrowDown', 'ArrowDown']);
    waitForState($page, '@note-mention-list [aria-selected=true]', "element.textContent.includes('Bia Lima')");
    $page->keys('@note-input', 'Enter');
    waitForGone($page, '@note-mention-list');
    waitForEditorText($page, '@note-input', '@Bia Lima');

    $page->typeSlowly('@note-input', 'hi @zzz', 10);
    waitForText($page, '@note-mention-list', 'No teammate matches');
    $page->keys('@note-input', 'Escape');
    waitForGone($page, '@note-mention-list');
    waitForEditorText($page, '@note-input', '@Bia Lima hi @zzz');

    $page->keys('@note-input', 'Enter');
    waitFor($page, '@note-mention');

    $note = PostNote::query()->where('post_id', $post->id)->sole();
    expect($note->body)->toBe("@[user:{$bia->id}] hi @zzz");

    $page->click('@note-actions');
    $page->click('@note-edit');
    waitFor($page, '@note-edit-input');
    waitForEditorText($page, '@note-edit-input', '@Bia Lima hi @zzz');

    $page->typeSlowly('@note-edit-input', ' and @an', 10);
    waitForText($page, '@note-mention-option', 'Ana Souza');
    $page->keys('@note-edit-input', 'Tab');
    waitForGone($page, '@note-mention-list');
    $page->click('@note-edit-save');
    waitForCount($page, '@note-mention', 2);

    $page->click('@note-actions');
    $page->click('@note-edit');
    waitFor($page, '@note-edit-input');
    $page->typeSlowly('@note-edit-input', ' discarded', 10);
    $page->keys('@note-edit-input', 'Escape');
    waitForGone($page, '@note-edit-input');

    $page->assertNoJavaScriptErrors();

    expect($note->fresh()->body)->toBe("@[user:{$bia->id}] hi @zzz and @[user:{$ana->id}]");
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
        'body' => "@[user:{$admin->id}] @[user:{$member->id}] @[user:{$formerMember->id}] @[user:{$user->id}]",
    ])->mentions()->attach([$admin->id, $member->id, $formerMember->id, $user->id]);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));

    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForOpen($page, "@post-notes-section-{$post->id}");
    waitForCount($page, '@note-mention', 4);
    waitForText($page, '@note-body', '@Ana Souza @Bia Lima @Caio Former @Paulo Castellano');

    $tokyoTimes = json_encode([now('Asia/Tokyo')->format('H:i'), now('Asia/Tokyo')->addMinute()->format('H:i')]);

    $page->hover('[data-testid=note-mention]:nth-child(1)');
    waitForOpen($page, '@note-mention-card');
    waitFor($page, '@note-mention-card-time');
    waitForState($page, '@note-mention-card img', "element.getAttribute('src') === {$photoUrl}");
    waitForText($page, '@note-mention-card-name', 'Ana Souza');
    waitForState($page, '@note-mention-card-time', "{$tokyoTimes}.some((time) => element.textContent.includes(time + ' local time'))");

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

test('@all is offered first in the mention list, in the reader\'s language, and mentions everyone on the post', function () {
    Mail::fake();

    $user = User::factory()->create(['name' => 'Paulo Castellano', 'locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $teammate = workspaceMember($workspace, 'member', ['name' => 'Bia Lima']);

    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));

    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForOpen($page, "@post-notes-section-{$post->id}");

    $page->fill('@note-input', '@');
    waitForOpen($page, '@note-mention-list');
    waitForState($page, '[data-testid=note-mention-list] [role=option]', "element.dataset.testid === 'note-mention-option-everyone' && element.textContent.includes('@todos')");

    $page->fill('@note-input', 'Revisem @tod');
    waitForCount($page, '[data-testid=note-mention-list] [role=option]', 1);
    $page->assertCount('@note-mention-option', 0)
        ->assertVisible('@note-mention-option-everyone');
    $page->keys('@note-input', 'Enter');
    waitForGone($page, '@note-mention-list');
    waitForEditorText($page, '@note-input', 'Revisem @todos');

    $page->keys('@note-input', 'Enter');
    waitForText($page, '@note-mention-everyone', '@todos');
    $page->assertNoJavaScriptErrors();

    expect(PostNote::query()->where('post_id', $post->id)->value('body'))->toBe('Revisem @[everyone]');
    Mail::assertQueued(PostNoteMentioned::class, fn (PostNoteMentioned $mail) => $mail->hasTo($teammate->email));
});

test('the mention list follows the caret and the mouse, and narrows to a longer name as you type', function () {
    $user = User::factory()->create(['name' => 'Paulo Castellano']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    workspaceMember($workspace, 'member', ['name' => 'Ana']);
    workspaceMember($workspace, 'member', ['name' => 'Ana Lima']);

    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));

    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForOpen($page, "@post-notes-section-{$post->id}");

    $page->fill('@note-input', '@Ana');
    waitForOpen($page, '@note-mention-list');
    waitForCount($page, '@note-mention-option', 2);

    $page->hover('[data-testid=note-mention-option]:nth-child(1)');
    $page->hover('[data-testid=note-mention-option]:nth-child(2)');
    waitForState($page, '@note-mention-list [aria-selected=true]', "element.textContent.includes('Ana Lima')");

    $page->typeSlowly('@note-input', ' L', 10);
    waitForCount($page, '@note-mention-option', 1);
    waitForText($page, '@note-mention-option', 'Ana Lima');
    $page->keys('@note-input', 'Enter');
    waitForGone($page, '@note-mention-list');
    waitForEditorText($page, '@note-input', '@Ana Lima');

    $page->fill('@note-input', 'hello @an');
    waitForOpen($page, '@note-mention-list');
    $page->keys('@note-input', ['ArrowLeft', 'ArrowLeft', 'ArrowLeft']);
    waitForGone($page, '@note-mention-list');
    $page->keys('@note-input', ['ArrowRight', 'ArrowRight', 'ArrowRight']);
    waitForOpen($page, '@note-mention-list');
    $page->keys('@note-input', 'Escape');
    waitForGone($page, '@note-mention-list');
    waitForEditorText($page, '@note-input', 'hello @an');

    $page->assertNoJavaScriptErrors();

    expect(PostNote::query()->count())->toBe(0);
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
    waitFor($page, '@note-input br');
    waitForEditorText($page, '@note-input', 'First line');
    expect(PostNote::query()->where('post_id', $post->id)->exists())->toBeFalse();

    $page->keys('@note-input', ['Enter']);
    waitFor($page, '@note-body');
    waitForText($page, "@post-notes-count-{$post->id}", '1');

    $page->assertSeeIn('@note-body', 'First line')
        ->assertDisabled('@note-send')
        ->assertSeeIn("@post-notes-count-{$post->id}", '1')
        ->assertVisible("@post-notes-filled-icon-{$post->id}")
        ->assertNoJavaScriptErrors();

    expect(PostNote::query()->where('post_id', $post->id)->pluck('body')->all())->toBe(['First line']);
});

test('text typed while a note is sending stays in the composer without the sent text', function () {
    Mail::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@notes-empty');

    $holdRequests = <<<'JS'
        (() => {
            const send = XMLHttpRequest.prototype.send;
            const held = [];
            XMLHttpRequest.prototype.send = function (body) {
                held.push(() => send.call(this, body));
            };
            window.__release = () => {
                XMLHttpRequest.prototype.send = send;
                held.splice(0).forEach((go) => go());
            };
        })()
    JS;

    $page->script($holdRequests);
    $page->fill('@note-input', 'Sent part');
    waitForEditorText($page, '@note-input', 'Sent part');
    $page->keys('@note-input', ['Enter']);
    $page->typeSlowly('@note-input', ' typed after', 10);
    waitForEditorText($page, '@note-input', 'Sent part typed after');
    $page->script('window.__release()');
    waitForText($page, '@note-body', 'Sent part');
    waitForEditorText($page, '@note-input', 'typed after');

    $page->keys('@note-input', ['ControlOrMeta+a', 'Backspace']);
    $page->typeSlowly('@note-input', 'Second note', 10);
    waitForEditorText($page, '@note-input', 'Second note');
    $page->script($holdRequests);
    $page->keys('@note-input', ['Enter', 'Backspace']);
    $page->typeSlowly('@note-input', 's', 10);
    waitForEditorText($page, '@note-input', 'Second nots');
    $page->script('window.__release()');
    waitForCount($page, '@note-body', 2);
    waitForEditorText($page, '@note-input', 'Second nots');
    $page->assertNoJavaScriptErrors();

    expect(PostNote::query()->where('post_id', $post->id)->oldest('id')->pluck('body')->all())->toBe(['Sent part', 'Second note']);
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

test('a mention of someone who no longer exists reads as unknown in the note and in its edit', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $goneId = Str::uuid()->toString();
    $unknown = __('notes.mention_unknown');
    PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => "ask @[user:{$goneId}]"]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForText($page, '@note-body', "ask @{$unknown}");

    $page->click('@note-actions');
    $page->click('@note-edit');
    waitForEditorText($page, '@note-edit-input', "ask @{$unknown}");

    $page->assertNoJavaScriptErrors();
});

test('scrolling up the thread loads older notes, reacting or sending keeps them, and a link to an older note loads its pages and highlights it', function () {
    Mail::fake();
    config(['app.pagination.default' => 8]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $notes = collect(range(1, 20))->map(fn (int $number): PostNote => PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => $number === 1 ? 'The very first note' : "Note number {$number}",
        'created_at' => now()->subMinutes(30 - $number),
        'updated_at' => now()->subMinutes(30 - $number),
    ]));
    $olderNote = $notes[5];
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForCount($page, '@note-item', 8);
    $page->assertSeeIn('@notes-list', 'Note number 20')
        ->assertDontSeeIn('@notes-list', 'Note number 6');

    $page->script("document.querySelector('[data-testid=\"notes-list\"]').scrollTop = 0");
    waitForCount($page, '@note-item', 16, '>=');
    $page->assertSeeIn('@notes-list', 'Note number 6');

    $olderNoteSelector = "[data-note-id=\"{$olderNote->id}\"]";
    $page->hover($olderNoteSelector)->click("{$olderNoteSelector} [data-testid=note-react]");
    waitForOpen($page, '[data-slot=popover-content]:has(@emoji-picker-option)');
    $page->click('@emoji-picker-option');
    waitFor($page, "{$olderNoteSelector} [data-testid^=note-reaction-]");
    $loaded = $page->script("document.querySelectorAll('[data-testid=\"note-item\"]').length");
    expect($loaded)->toBeGreaterThanOrEqual(16);

    $page->fill('@note-input', 'A brand new note');
    $page->keys('@note-input', 'Enter');
    waitForCount($page, '@note-item', $loaded + 1);
    $page->assertSeeIn('@notes-list', 'A brand new note')
        ->assertSeeIn('@notes-list', 'Note number 6')
        ->assertNoJavaScriptErrors();

    $oldest = "[data-note-id=\"{$notes->first()->id}\"]";
    $linked = visit(route('app.posts.edit', ['post' => $post, 'comment' => $notes->first()->id]));
    waitForState($linked, $oldest, "element.dataset.highlighted === 'true'");
    waitForState($linked, $oldest, "(() => { const list = document.querySelector('[data-testid=notes-list]').getBoundingClientRect(); const note = element.getBoundingClientRect(); return note.top >= list.top - 1 && note.bottom <= list.bottom + 1; })()");
    $linked->assertAttribute($oldest, 'data-highlighted', 'true')
        ->assertNoJavaScriptErrors();
});

test('a note repeated by an older page after a new one disappears when deleted, and a refused delete or reaction says so', function () {
    Mail::fake();
    config(['app.pagination.default' => 8]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $notes = collect(range(1, 20))->map(fn (int $number): PostNote => PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => "Note number {$number}",
        'created_at' => now()->subMinutes(30 - $number),
        'updated_at' => now()->subMinutes(30 - $number),
    ]));
    $boundary = $notes[12];
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForCount($page, '@note-item', 8);
    $page->fill('@note-input', 'A brand new note');
    $page->keys('@note-input', 'Enter');
    waitForCount($page, '@note-item', 9);

    $page->script("document.querySelector('[data-testid=\"notes-list\"]').scrollTop = 0");
    waitForText($page, '@notes-list', 'Note number 6');
    $boundaryNote = "[data-note-id=\"{$boundary->id}\"]";
    $page->assertCount($boundaryNote, 1);

    $page->hover($boundaryNote)->click("{$boundaryNote} [data-testid=note-actions]");
    waitFor($page, '@note-delete');
    $page->click('@note-delete');
    waitForGone($page, $boundaryNote);
    $page->assertMissing($boundaryNote);
    expect($boundary->fresh())->toBeNull();

    $gone = $notes[9];
    $goneNote = "[data-note-id=\"{$gone->id}\"]";
    $page->script(<<<'JS'
        (() => {
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.send = function (body) {
                this.dispatchEvent(new ProgressEvent('error'));
                this.dispatchEvent(new ProgressEvent('loadend'));
            };
            window.__restoreSend = () => { XMLHttpRequest.prototype.send = send; };
        })()
    JS);
    $page->hover($goneNote)->click("{$goneNote} [data-testid=note-react]");
    waitForOpen($page, '[data-slot=popover-content]:has(@emoji-picker-option)');
    $page->click('@emoji-picker-option');
    waitForText($page, '@note-error-toast', __('notes.errors.not_reacted'));
    $page->keys('@emoji-picker-option', 'Escape');
    waitForGone($page, '[data-slot=popover-content]:has(@emoji-picker-option)');

    $page->hover($goneNote)->click("{$goneNote} [data-testid=note-actions]");
    waitFor($page, '@note-delete');
    $page->click('@note-delete');
    waitForText($page, '@note-error-toast', __('notes.errors.not_deleted'));
    $page->script('window.__restoreSend()');
    $page->assertPresent($goneNote)->assertNoJavaScriptErrors();
    expect($gone->fresh())->not->toBeNull();
});

test('opening another post notes closes the open thread, and closing a thread clears it from the link', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    [$first, $second] = collect(['First post', 'Second post'])->map(function (string $content) use ($workspace, $user): Post {
        $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => $content]);
        PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => "A note on {$content}"]);

        return $post;
    })->all();
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$first->id}");
    $page->click("@post-notes-trigger-{$first->id}");
    waitForText($page, "@post-notes-section-{$first->id} @note-body", 'A note on First post');
    waitForQueryParameter($page, 'notes', $first->id);

    $page->click("@post-notes-trigger-{$second->id}");
    waitForText($page, "@post-notes-section-{$second->id} @note-body", 'A note on Second post');
    waitForQueryParameter($page, 'notes', $second->id);
    waitForState($page, "@post-notes-trigger-{$first->id}", "element.dataset.state === 'closed'");
    $page->assertAttribute("@post-notes-trigger-{$first->id}", 'data-state', 'closed');

    $page->click("@post-notes-trigger-{$second->id}");
    waitForCondition($page, "!new URLSearchParams(location.search).has('notes')");
    waitForState($page, "@post-notes-trigger-{$second->id}", "element.dataset.state === 'closed'");
    $page->assertAttribute("@post-notes-trigger-{$second->id}", 'data-state', 'closed')
        ->assertNoJavaScriptErrors();
});

test('Backspace removes a mention chip whole, leaving no @ behind', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    workspaceMember($workspace, 'member', ['name' => 'Bia Lima']);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-input');

    $page->hover('@note-input');
    $page->typeSlowly('@note-input', 'Oi @bia', 10);
    waitForCount($page, '@note-mention-option', 1);
    $page->keys('@note-input', 'Enter');
    waitFor($page, '@note-input-mention');
    $page->keys('@note-input', ['Backspace', 'Backspace']);
    waitForGone($page, '@note-input-mention');
    waitForEditorText($page, '@note-input', 'Oi');

    $page->assertNoJavaScriptErrors();
});

test('Enter sends while the mention list finds nobody', function () {
    Mail::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    workspaceMember($workspace, 'member', ['name' => 'Bia Lima']);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-input');

    $page->hover('@note-input');
    $page->typeSlowly('@note-input', 'hi @zzz', 10);
    waitForText($page, '@note-mention-list', 'No teammate matches');
    $page->keys('@note-input', 'Enter');
    waitFor($page, '@note-item');
    $page->assertSeeIn('@note-body', 'hi @zzz')
        ->assertNoJavaScriptErrors();

    expect(PostNote::query()->pluck('body')->all())->toBe(['hi @zzz']);
});

test('a note opened in the editor and saved unchanged keeps every line and mention', function () {
    Mail::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $bia = workspaceMember($workspace, 'member', ['name' => 'Bia Lima']);
    $ana = workspaceMember($workspace, 'member', ['name' => 'Ana Souza']);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $body = "(@[user:{$bia->id}]) and cc:@[user:{$ana->id}]\n\n@[everyone] さん、@[user:{$bia->id}]確認お願いします";
    $note = PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => $body]);
    $note->mentions()->attach([$bia->id, $ana->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-item');
    $page->hover('@note-item')->click('@note-actions');
    waitFor($page, '@note-edit');
    $page->click('@note-edit');
    waitForCount($page, '@note-edit-input [data-testid=note-input-mention]', 4);
    expect($page->script("[...document.querySelectorAll('[data-testid=\"note-edit-input\"] [data-testid=\"note-input-mention\"]')].map((chip) => chip.textContent)"))
        ->toBe(['@Bia Lima', '@Ana Souza', '@'.__('notes.mention_everyone.word'), '@Bia Lima']);

    $page->click('@note-edit-save');
    waitForGone($page, '@note-edit-input');
    $page->assertNoJavaScriptErrors();

    expect($note->fresh()->body)->toBe($body);
});

test('editing the last note of a long thread scrolls its Save button into view', function () {
    Mail::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $notes = collect(range(1, 10))->map(fn (int $number): PostNote => PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => "Note number {$number}",
        'created_at' => now()->subMinutes(20 - $number),
        'updated_at' => now()->subMinutes(20 - $number),
    ]));
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitForCount($page, '@note-item', 10);

    $last = "[data-note-id=\"{$notes->last()->id}\"]";
    $page->hover($last)->click("{$last} [data-testid=note-actions]");
    waitFor($page, '@note-edit');
    $page->click('@note-edit');
    waitForState($page, '@note-edit-save', "(() => { const list = document.querySelector('[data-testid=notes-list]').getBoundingClientRect(); const save = element.getBoundingClientRect(); return save.top >= list.top - 1 && save.bottom <= list.bottom + 1; })()");
    $page->assertNoJavaScriptErrors();
});

test('an edit the server refuses keeps the editor open with the text and says why', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'hello team']);
    $note = PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => 'Short note']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, "@post-notes-trigger-{$post->id}");
    $page->click("@post-notes-trigger-{$post->id}");
    waitFor($page, '@note-item');
    $page->hover('@note-item')->click('@note-actions');
    waitFor($page, '@note-edit');
    $page->click('@note-edit');
    waitForEditorText($page, '@note-edit-input', 'Short note');

    $page->keys('@note-edit-input', 'ControlOrMeta+a');
    $page->script("document.execCommand('insertText', false, 'a'.repeat(2001))");
    waitForState($page, '@note-edit-input', 'element.innerText.trim().length === 2001');
    $page->click('@note-edit-save');
    waitFor($page, '@note-error-toast');

    $page->assertSeeIn('@note-error-toast', __('notes.errors.too_long', ['max' => 2000]))
        ->assertVisible('@note-edit-input')
        ->assertScript("document.querySelector('[data-testid=\"note-edit-input\"]').innerText.trim().length", 2001)
        ->assertNoJavaScriptErrors();
    expect($note->fresh()->body)->toBe('Short note');
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
    $page->fill('@note-input', 'First line');
    $page->keys('@note-input', ['Shift+Enter']);
    $page->typeSlowly('@note-input', 'Second line', 10);
    $page->keys('@note-input', ['Shift+Enter']);
    $page->typeSlowly('@note-input', 'Third line', 10);
    $grown = $page->script("document.querySelector('[data-testid=\"note-input\"]').getBoundingClientRect().height");
    expect($grown)->toBeGreaterThan($singleLine)
        ->and($page->script("document.querySelector('[data-testid=\"note-composer\"]').getBoundingClientRect().height"))->toBeGreaterThanOrEqual($grown);

    waitForEditorText($page, '@note-input', "First line\nSecond line\nThird line");
    $page->keys('@note-input', ['Enter']);
    waitFor($page, '@note-body');
    waitForText($page, "@post-notes-count-{$post->id}", '1');
    expect(PostNote::query()->where('post_id', $post->id)->sole()->body)->toBe("First line\nSecond line\nThird line");
    $page->assertSeeIn("@post-notes-count-{$post->id}", '1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true);

    $page->hover('@note-item')->click('@note-actions');
    waitFor($page, '@note-edit');
    $page->click('@note-edit');
    waitForEditorText($page, '@note-edit-input', "First line\nSecond line\nThird line");
    $page->keys('@note-edit-input', 'ControlOrMeta+a');
    $page->typeSlowly('@note-edit-input', 'Edited note', 10);
    waitForEditorText($page, '@note-edit-input', 'Edited note');
    $page->assertScript("document.querySelector('[data-testid=\"note-edit-input\"]').innerText.trim()", 'Edited note')
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
