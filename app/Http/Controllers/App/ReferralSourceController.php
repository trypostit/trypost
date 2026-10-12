<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\PostHog\WelcomeEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ReferralSource\StoreReferralSourceRequest;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;

class ReferralSourceController extends Controller
{
    public function store(StoreReferralSourceRequest $request, PostHogService $postHog): RedirectResponse
    {
        $user = $request->user();
        $referralSource = (string) $request->validated('referral_source');

        $user->update(['referral_source' => $referralSource]);

        $postHog->identify($user->id, [
            'referral_source' => $referralSource,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::Referral->value,
            ['referral_source' => $referralSource],
            $user->account,
        );

        return back();
    }
}
