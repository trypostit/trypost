<?php

declare(strict_types=1);

namespace App\Jobs\Affonso;

use App\Models\User;
use App\Services\Affonso\AffonsoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Records a referred signup as a lead of its affiliate, the server-side twin
 * of the pixel's `Affonso.signup()`. A rate limit or a server error is
 * retried (the call is idempotent per click); any other refusal (an unknown
 * click, a bad key) fails at once, since sending it again gets the same answer.
 */
class RecordAffiliateSignup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 60, 300, 900];

    public function __construct(public string $userId, public string $clickId) {}

    public function handle(AffonsoClient $affonso): void
    {
        $user = User::find($this->userId);

        if (blank($user)) {
            return;
        }

        try {
            $affonso->recordSignup($this->clickId, $user->email, $user->id);
        } catch (RequestException $exception) {
            if ($exception->response->clientError() && ! $exception->response->tooManyRequests()) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }
}
