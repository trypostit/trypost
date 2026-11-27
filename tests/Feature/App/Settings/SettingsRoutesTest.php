<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('workspace resources live under settings', function (string $name, string $path) {
    expect(route($name, absolute: false))->toBe($path);
})->with([
    ['app.signatures.index', '/settings/workspace/signatures'],
    ['app.labels.index', '/settings/workspace/labels'],
    ['app.webhooks.index', '/settings/workspace/webhooks'],
]);
