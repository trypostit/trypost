<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutAndInvalidateSession
{
    /**
     * Log the user out while the row still exists, then invalidate the session.
     * SessionGuard cycles the remember token via save() — calling this after
     * $user->delete() can re-insert the deleted user.
     *
     * @param  array{banner?: string|null, bannerStyle?: string|null}|null  $reflash
     */
    public static function execute(?Request $request = null, ?array $reflash = null): void
    {
        $request ??= request();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $banner = data_get($reflash, 'banner');

        if ($banner !== null) {
            session()->flash('flash.banner', $banner);
            session()->flash('flash.bannerStyle', data_get($reflash, 'bannerStyle', 'info'));
        }
    }
}
