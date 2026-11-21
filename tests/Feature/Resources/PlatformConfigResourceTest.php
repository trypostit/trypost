<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Http\Resources\App\PlatformConfigResource;
use App\Models\SocialAccount;

test('a channel carries the copy of each feature whose scope it lacks, for the composer', function () {
    config(['trypost.platforms.x.permissions.thread_replies' => [
        'scope' => 'tweet.moderate.write',
        'message' => 'posts.form.thread.reply_empty',
        'member_message' => 'posts.form.thread.unsupported',
    ]]);
    $without = SocialAccount::factory()->create(['platform' => Platform::X, 'scopes' => ['tweet.write']]);
    $with = SocialAccount::factory()->create(['platform' => Platform::X, 'scopes' => ['tweet.write', 'tweet.moderate.write']]);

    expect(PlatformConfigResource::make($without)->resolve()['missingPermissions'])
        ->toEqual((object) ['thread_replies' => ['message' => 'posts.form.thread.reply_empty', 'member_message' => 'posts.form.thread.unsupported']])
        ->and(PlatformConfigResource::make($with)->resolve()['missingPermissions'])->toEqual((object) []);
});
