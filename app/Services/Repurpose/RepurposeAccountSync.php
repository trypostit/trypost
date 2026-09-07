<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Actions\Repurpose\ActivateRepurpose;
use App\Actions\Repurpose\ResumeRepurpose;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\PauseReason;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Support\Repurpose\RepurposeTransition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RepurposeAccountSync
{
    /** @var array<int, string> */
    private const WATCHED_ATTRIBUTES = ['status', 'is_active', 'platform'];

    public function accountRemoved(SocialAccount $account): void
    {
        $this->guard(function () use ($account): void {
            foreach ($this->sourcedBy($account) as $repurpose) {
                $this->pause($repurpose, PauseReason::SourceRemoved);
            }

            $this->pruneDestination($account);
        }, $account);
    }

    public function accountChanged(SocialAccount $account): void
    {
        if (! $account->wasChanged(self::WATCHED_ATTRIBUTES)) {
            return;
        }

        $this->guard(function () use ($account): void {
            if ($account->wasChanged('platform')) {
                $this->realignDestinations($account);
            }

            if ($this->isUsable($account)) {
                $this->resumeRecovered($account);

                return;
            }

            foreach ($this->sourcedBy($account) as $repurpose) {
                $this->pause($repurpose, PauseReason::SourceUnavailable);
            }
        }, $account);
    }

    private function isUsable(SocialAccount $account): bool
    {
        return SocialAccount::query()
            ->whereKey($account->id)
            ->where('is_active', true)
            ->where('status', AccountStatus::Connected)
            ->exists();
    }

    /**
     * @return Collection<int, Repurpose>
     */
    private function sourcedBy(SocialAccount $account): Collection
    {
        return Repurpose::query()
            ->where('source_social_account_id', $account->id)
            ->where('status', Status::Active)
            ->get();
    }

    private function resumeRecovered(SocialAccount $account): void
    {
        $candidates = Repurpose::query()
            ->where('source_social_account_id', $account->id)
            ->where('status', Status::Paused)
            ->where('paused_reason', PauseReason::SourceUnavailable)
            ->get();

        foreach ($candidates as $repurpose) {
            try {
                ActivateRepurpose::assertSourceUsable($repurpose);
                ActivateRepurpose::assertDestinationsPublishable($repurpose);
            } catch (ValidationException) {
                continue;
            }

            ResumeRepurpose::execute($repurpose);
        }
    }

    private function pruneDestination(SocialAccount $account): void
    {
        foreach ($this->destinedFor($account) as $repurpose) {
            $remaining = array_values(array_filter(
                $repurpose->destinations,
                fn (array $destination): bool => data_get($destination, 'social_account_id') !== $account->id,
            ));

            $repurpose->update(['destinations' => $remaining]);

            if ($remaining === []) {
                $this->pause($repurpose, PauseReason::NoDestinations);
            }
        }
    }

    private function realignDestinations(SocialAccount $account): void
    {
        $supported = array_map(
            fn (ContentType $contentType): string => $contentType->value,
            ContentType::forPlatform($account->platform),
        );

        foreach ($this->destinedFor($account) as $repurpose) {
            $destinations = array_map(function (array $destination) use ($account, $supported): array {
                if (data_get($destination, 'social_account_id') !== $account->id) {
                    return $destination;
                }

                if (in_array(data_get($destination, 'content_type'), $supported, true)) {
                    return $destination;
                }

                $destination['content_type'] = ContentType::defaultFor($account->platform)->value;

                return $destination;
            }, $repurpose->destinations);

            $repurpose->update(['destinations' => array_values($destinations)]);
        }
    }

    /**
     * @return SupportCollection<int, Repurpose>
     */
    private function destinedFor(SocialAccount $account): SupportCollection
    {
        return Repurpose::query()
            ->where('workspace_id', $account->workspace_id)
            ->get()
            ->filter(fn (Repurpose $repurpose): bool => collect($repurpose->destinations)
                ->contains(fn (array $destination): bool => data_get($destination, 'social_account_id') === $account->id))
            ->values();
    }

    private function pause(Repurpose $repurpose, PauseReason $reason): void
    {
        RepurposeTransition::applyIfPossible(
            $repurpose,
            [Status::Active],
            fn (Repurpose $locked) => $locked->update([
                'status' => Status::Paused,
                'paused_reason' => $reason,
            ]),
        );
    }

    private function guard(callable $work, SocialAccount $account): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            Log::error('Repurpose account sync failed', [
                'social_account_id' => $account->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
