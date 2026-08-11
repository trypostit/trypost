<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Str;

test('fromId resolves an existing invite by a valid uuid', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
        'invited_by' => $owner->id,
    ]);

    expect(Invite::fromId($invite->id))->not->toBeNull()
        ->and(Invite::fromId($invite->id)->id)->toBe($invite->id);
});

test('fromId returns null for a well-formed but unknown uuid', function () {
    expect(Invite::fromId((string) Str::uuid()))->toBeNull();
});

test('fromId returns null for a non-uuid string without touching the database', function () {
    expect(Invite::fromId('not-a-uuid'))->toBeNull();
});

test('fromId returns null for an empty or missing value', function () {
    expect(Invite::fromId(''))->toBeNull()
        ->and(Invite::fromId(null))->toBeNull();
});
