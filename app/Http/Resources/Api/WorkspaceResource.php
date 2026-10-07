<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkspaceResource extends JsonResource
{
    private ?User $viewer = null;

    /**
     * Include what this user may do in the workspace as `me`.
     */
    public function for(User $viewer): static
    {
        $this->viewer = $viewer;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            'me' => $this->when($this->viewer !== null, fn (): array => [
                'is_admin' => $this->viewer->isWorkspaceAdmin($this->resource),
                'requires_approval' => $this->viewer->requiresApprovalIn($this->resource),
                'publishes_directly' => $this->viewer->canPublishDirectlyIn($this->resource),
            ]),
        ];
    }
}
