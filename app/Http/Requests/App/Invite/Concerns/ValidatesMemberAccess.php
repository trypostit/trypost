<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Invite\Concerns;

trait ValidatesMemberAccess
{
    /**
     * @return array<string, list<string>>
     */
    protected function memberAccessRules(): array
    {
        return [
            'is_admin' => ['required', 'boolean'],
            'requires_approval' => ['required', 'boolean'],
        ];
    }

    /**
     * The membership flags to store: an admin never needs approval.
     *
     * @return array{is_admin: bool, requires_approval: bool}
     */
    public function memberAccess(): array
    {
        $isAdmin = $this->boolean('is_admin');

        return [
            'is_admin' => $isAdmin,
            'requires_approval' => ! $isAdmin && $this->boolean('requires_approval'),
        ];
    }
}
