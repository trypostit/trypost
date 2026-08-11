<?php

declare(strict_types=1);

use App\Enums\Auth\SocialAuthProvider;

test('social auth provider has values and labels', function () {
    expect(SocialAuthProvider::Google->value)->toBe('google');
    expect(SocialAuthProvider::Google->label())->toBe('Google');

    expect(SocialAuthProvider::GitHub->value)->toBe('github');
    expect(SocialAuthProvider::GitHub->label())->toBe('GitHub');
});

test('isEnabled reflects the matching config key', function () {
    config(['trypost.google_auth_enabled' => true, 'trypost.github_auth_enabled' => false]);

    expect(SocialAuthProvider::Google->isEnabled())->toBeTrue();
    expect(SocialAuthProvider::GitHub->isEnabled())->toBeFalse();
});

test('tryFrom returns null for an unknown provider', function () {
    expect(SocialAuthProvider::tryFrom('twitter'))->toBeNull();
});
