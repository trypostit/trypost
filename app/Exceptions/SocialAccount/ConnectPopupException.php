<?php

declare(strict_types=1);

namespace App\Exceptions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Abort a social connect flow and close the popup with a reason.
 *
 * Rendering lives on the exception so the session and permission guards that
 * open every connect action stay a single line instead of six.
 *
 * An expired popup session is a normal outcome, not an incident, so this never
 * reaches the error log.
 */
class ConnectPopupException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $messageKey,
        public readonly ?Platform $platform = null,
    ) {
        parent::__construct("Social connect aborted: {$messageKey}");
    }

    public function render(Request $request): Response
    {
        session()->forget(['social_connect_workspace', 'social_reconnect_id']);

        return Inertia::render('accounts/PopupCallback', [
            'success' => false,
            'message' => __("accounts.popup_callback.{$this->messageKey}"),
            'platform' => $this->platform?->value,
            'onboardingProgress' => false,
        ]);
    }
}
