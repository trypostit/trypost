<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Http\Resources\App\PlatformConfigResource;
use App\Models\SocialAccount;

test('a channel without the reply scope its network asks for carries the copy the composer shows', function () {
    config(['trypost.platforms.x.replies' => [
        'max' => 24,
        'scope' => 'tweet.moderate.write',
        'message' => 'posts.form.thread.reply_empty',
        'member_message' => 'posts.form.thread.unsupported',
    ]]);
    $without = SocialAccount::factory()->create(['platform' => Platform::X, 'scopes' => ['tweet.write']]);
    $with = SocialAccount::factory()->create(['platform' => Platform::X, 'scopes' => ['tweet.write', 'tweet.moderate.write']]);

    expect(PlatformConfigResource::make($without)->resolve()['repliesPermission'])
        ->toBe(['message' => 'posts.form.thread.reply_empty', 'member_message' => 'posts.form.thread.unsupported'])
        ->and(PlatformConfigResource::make($with)->resolve()['repliesPermission'])->toBeNull();
});
