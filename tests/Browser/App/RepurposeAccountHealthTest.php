<?php

declare(strict_types=1);

use App\Enums\Repurpose\PauseReason;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

test('a repurpose whose source was deleted explains itself instead of rendering a hole', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $destination = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);

    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => null,
        'status' => Status::Paused,
        'paused_reason' => PauseReason::SourceRemoved,
        'destinations' => [[
            'social_account_id' => $destination->id,
            'content_type' => 'tiktok_video',
            'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value],
        ]],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.repurposes.show', $repurpose));

    waitFor($page, '@repurpose-health-banner');

    $page->assertSee(__('repurposes.health.source_missing'))
        ->assertSee(__('repurposes.summary.no_source'))
        ->assertMissing('@repurpose-source-avatar')
        ->assertNoJavaScriptErrors();
});
