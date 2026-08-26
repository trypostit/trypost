<?php

declare(strict_types=1);

use App\Support\Social\SocialMediaDerivativeDirectory;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

test('command deletes derivatives older than the pull window from every registered directory', function () {
    $oldCrop = SocialMediaDerivativeDirectory::CROPS.'/old.jpg';
    $oldTikTok = SocialMediaDerivativeDirectory::TIKTOK_PHOTOS.'/old.jpg';

    Storage::put($oldCrop, 'fake-bytes');
    Storage::put($oldTikTok, 'fake-bytes');
    touch(Storage::path($oldCrop), now()->subHours(2)->getTimestamp());
    touch(Storage::path($oldTikTok), now()->subHours(2)->getTimestamp());

    $this->artisan('social:prune-derivatives')->assertExitCode(0);

    Storage::assertMissing($oldCrop);
    Storage::assertMissing($oldTikTok);
});

test('command leaves recent derivatives alone', function () {
    $recent = SocialMediaDerivativeDirectory::CROPS.'/recent.jpg';

    Storage::put($recent, 'fake-bytes');

    $this->artisan('social:prune-derivatives')->assertExitCode(0);

    Storage::assertExists($recent);
});

test('command is a no-op when no derivative directories exist yet', function () {
    $this->artisan('social:prune-derivatives')->assertExitCode(0);
});

test('--hours overrides the default retention window', function () {
    $fortyMinutesOld = SocialMediaDerivativeDirectory::CROPS.'/forty-min.jpg';

    Storage::put($fortyMinutesOld, 'fake-bytes');
    touch(Storage::path($fortyMinutesOld), now()->subMinutes(40)->getTimestamp());

    // Default 1h window leaves a 40-minute-old file alone.
    $this->artisan('social:prune-derivatives')->assertExitCode(0);
    Storage::assertExists($fortyMinutesOld);

    // An explicit 0h window prunes the same file immediately.
    $this->artisan('social:prune-derivatives', ['--hours' => 0])->assertExitCode(0);
    Storage::assertMissing($fortyMinutesOld);
});
