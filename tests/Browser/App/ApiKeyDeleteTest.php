<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

test('api key deletion requires the translated delete keyword, not the key name', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $result = $user->createToken('Keyword key');
    $token = AccessToken::find($result->token->id);
    $token->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    $this->actingAs($user->fresh());

    $keyword = __('common.confirm_modal.delete_keyword');

    visit(route('app.api-keys.index'))
        ->click("@api-key-menu-{$token->id}")
        ->click("@delete-api-key-{$token->id}")
        ->assertVisible('@confirm-delete-modal')
        ->fill('@confirm-delete-input', 'Keyword key')
        ->assertAttribute('@confirm-delete-action', 'disabled', '')
        ->fill('@confirm-delete-input', mb_strtolower($keyword))
        ->assertAttribute('@confirm-delete-action', 'disabled', '')
        ->fill('@confirm-delete-input', $keyword)
        ->click('@confirm-delete-action')
        ->assertMissing('@confirm-delete-modal')
        ->assertNoJavaScriptErrors();

    expect($token->fresh()->revoked)->toBeTrue();
});

test('regenerating an api key asks for confirmation and shows the new key', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $result = $user->createToken('Rotating key');
    $token = AccessToken::find($result->token->id);
    $token->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    $this->actingAs($user->fresh());

    $keyword = __('settings.api_keys.regenerate_modal.keyword');

    $page = visit(route('app.api-keys.index'))
        ->click("@api-key-menu-{$token->id}")
        ->click("@regenerate-api-key-{$token->id}")
        ->assertVisible('@confirm-delete-modal');

    $page->script('navigator.clipboard.writeText = async () => {};');

    $page->click('@confirm-delete-copy-keyword')
        ->assertVisible('@confirm-delete-keyword-copied')
        ->assertScript("document.querySelector('[data-sonner-toast]') === null", true)
        ->fill('@confirm-delete-input', 'Rotating key')
        ->assertAttribute('@confirm-delete-action', 'disabled', '')
        ->fill('@confirm-delete-input', mb_strtolower($keyword))
        ->assertAttribute('@confirm-delete-action', 'disabled', '')
        ->fill('@confirm-delete-input', $keyword)
        ->click('@confirm-delete-action')
        ->assertVisible('@api-key-generated-dialog')
        ->assertNoJavaScriptErrors();

    expect($token->fresh()->revoked)->toBeTrue()
        ->and(AccessToken::where('user_id', $user->id)->where('revoked', false)->sole()->name)
        ->toBe('Rotating key');
});
