<?php

declare(strict_types=1);

use App\Actions\Auth\LogoutAndInvalidateSession;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

test('logout invalidates the session and can reflash a banner', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $request = request();
    $request->setLaravelSession(app('session.store'));

    expect(Auth::check())->toBeTrue();

    LogoutAndInvalidateSession::execute($request, [
        'banner' => 'gone',
        'bannerStyle' => 'danger',
    ]);

    expect(Auth::check())->toBeFalse();
    expect(session('flash.banner'))->toBe('gone');
    expect(session('flash.bannerStyle'))->toBe('danger');
});
