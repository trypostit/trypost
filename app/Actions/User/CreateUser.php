<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Workspace\CreateWorkspace;
use App\Enums\Plan\Slug;
use App\Jobs\PostHog\SyncUser;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\GtmServerService;
use App\Services\PostHogService;
use Illuminate\Support\Facades\DB;

class CreateUser
{
    /**
     * @param  array{name: string, email: string, password?: string, google_id?: string, github_id?: string, email_verified_at?: \DateTimeInterface|null, is_invite?: bool, registration_ip?: string|null}  $data
     * @param  array<string, string>  $utmParameters
     */
    public static function execute(array $data, array $utmParameters = []): User
    {
        $isInviteRegistration = (bool) data_get($data, 'is_invite', false);

        $user = DB::transaction(function () use ($data, $utmParameters, $isInviteRegistration): User {
            $requiresCardForTrial = (bool) config('trypost.billing.require_card_for_trial', true);
            $accountAttributes = [
                'name' => data_get($data, 'name')."'s Account",
                'billing_email' => data_get($data, 'email'),
            ];

            if (! $requiresCardForTrial) {
                $accountAttributes['plan_id'] = Plan::where('slug', Slug::Workspace)->value('id');
                $accountAttributes['trial_ends_at'] = now()->addDays(config('cashier.trial_days'));
            }

            $account = Account::create($accountAttributes);

            $user = User::create(array_merge([
                'name' => data_get($data, 'name'),
                'email' => data_get($data, 'email'),
                'password' => data_get($data, 'password'),
                'google_id' => data_get($data, 'google_id'),
                'github_id' => data_get($data, 'github_id'),
                'email_verified_at' => data_get($data, 'email_verified_at', $isInviteRegistration ? now() : null),
                'account_id' => $account->id,
                'registration_ip' => data_get($data, 'registration_ip'),
            ], $utmParameters));

            $account->update(['owner_id' => $user->id]);

            if (! $isInviteRegistration) {
                CreateWorkspace::execute($user, ['name' => data_get($data, 'name')."'s Workspace"]);
            }

            return $user;
        });

        if (PostHogService::isEnabled()) {
            SyncUser::dispatch((string) $user->id);
        }

        if (! $isInviteRegistration && GtmServerService::isEnabled()) {
            $authProvider = match (true) {
                (bool) data_get($data, 'google_id') => 'google',
                (bool) data_get($data, 'github_id') => 'github',
                default => 'email',
            };

            (new GtmServerService)->capture('sign_up', ['auth_provider' => $authProvider], (string) $user->id);
        }

        return $user;
    }
}
