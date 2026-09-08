<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Plan\Slug;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Keyed by slug so a production run archives the legacy plan and adds the
     * two new ones without touching accounts.plan_id, which references plans
     * by UUID. A null workspace_limit means unlimited.
     */
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => Slug::Socials],
            [
                'name' => 'Socials',
                'stripe_monthly_price_id' => env('STRIPE_SOCIALS_MONTHLY'),
                'stripe_yearly_price_id' => env('STRIPE_SOCIALS_YEARLY'),
                'workspace_limit' => 1,
                'sort' => 1,
                'is_archived' => false,
            ],
        );

        Plan::updateOrCreate(
            ['slug' => Slug::Workspaces],
            [
                'name' => 'Workspaces',
                'stripe_monthly_price_id' => env('STRIPE_WORKSPACES_MONTHLY'),
                'stripe_yearly_price_id' => env('STRIPE_WORKSPACES_YEARLY'),
                'workspace_limit' => null,
                'sort' => 2,
                'is_archived' => false,
            ],
        );

        Plan::updateOrCreate(
            ['slug' => Slug::Workspace],
            [
                'name' => 'Workspace',
                'stripe_monthly_price_id' => env('STRIPE_WORKSPACE_MONTHLY'),
                'stripe_yearly_price_id' => env('STRIPE_WORKSPACE_YEARLY'),
                'workspace_limit' => 1,
                'sort' => 3,
                'is_archived' => true,
            ],
        );
    }
}
