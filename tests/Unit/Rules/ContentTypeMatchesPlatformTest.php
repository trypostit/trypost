<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Rules\ContentTypeMatchesPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runMatchesPlatformRule(string $contentType, ?string $accountId, array $extraData = []): array
{
    $errors = [];
    $rule = (new ContentTypeMatchesPlatform)->setData(array_merge([
        'platforms' => [
            ['social_account_id' => $accountId, 'content_type' => $contentType],
        ],
    ], $extraData));

    $rule->validate('platforms.0.content_type', $contentType, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

test('passes when content_type matches the social account platform', function () {
    $workspace = Workspace::factory()->create();
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    expect(runMatchesPlatformRule(ContentType::LinkedInPost->value, $linkedin->id))->toBe([]);
});

test('fails when content_type belongs to a different platform', function () {
    $workspace = Workspace::factory()->create();
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $errors = runMatchesPlatformRule(ContentType::XPost->value, $linkedin->id);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('not compatible');
});

test('passes when an instagram content_type is paired with an instagram-facebook account', function () {
    $workspace = Workspace::factory()->create();
    $igFacebook = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::InstagramFacebook,
    ]);

    // instagram_feed lists Instagram as its primary platform but is also
    // compatible with InstagramFacebook accounts via compatiblePlatforms().
    expect(runMatchesPlatformRule(ContentType::InstagramFeed->value, $igFacebook->id))->toBe([]);
    expect(runMatchesPlatformRule(ContentType::InstagramReel->value, $igFacebook->id))->toBe([]);
});

test('skips validation when social_account_id is missing', function () {
    expect(runMatchesPlatformRule(ContentType::XPost->value, null))->toBe([]);
});

test('skips validation without querying the database when social_account_id is not a uuid', function () {
    // Regression: a non-uuid social_account_id (e.g. an MCP client sending a
    // placeholder string) must not reach SocialAccount::find(), which throws
    // a QueryException on Postgres for invalid uuid input instead of
    // returning no rows.
    expect(runMatchesPlatformRule(ContentType::XPost->value, 'threads-account'))->toBe([]);
});

test('skips validation when content_type is not a known enum value', function () {
    $workspace = Workspace::factory()->create();
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    // Unknown content_types are caught by Rule::in elsewhere; this rule
    // intentionally no-ops so it doesn't double-report.
    expect(runMatchesPlatformRule('completely_made_up', $linkedin->id))->toBe([]);
});
