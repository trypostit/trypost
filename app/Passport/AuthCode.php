<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\AuthCode as PassportAuthCode;

class AuthCode extends PassportAuthCode
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked' => 'bool',
            'expires_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
