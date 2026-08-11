import type { Auth } from './types';

/**
 * Push app + identity context to GTM's dataLayer on every page load. The
 * pushes are no-ops when GTM isn't configured (the array still exists in
 * memory; nothing reads it). Self-hosted instances without GTM_ID set
 * incur zero error noise.
 *
 * Billing is account-scoped (not workspace-scoped) in trypost, so the
 * plan/subscription fields are emitted under `account_*` keys.
 */
export const initializeDataLayer = (
    auth: Auth | undefined,
    applicationUrl: string,
    env: string,
): void => {
    window.dataLayer = window.dataLayer || [];

    window.dataLayer.push({
        app_url: applicationUrl,
        app_env: env,
        app_context: 'app',
    });

    if (!auth?.user) {
        return;
    }

    window.dataLayer.push({
        user_id: auth.user.id,
        user_email: auth.user.email,
        user_name: auth.user.name,
        user_created_at: auth.user.created_at,
    });

    if (auth.account) {
        window.dataLayer.push({
            account_id: auth.account.id,
            account_name: auth.account.name,
            account_created_at: auth.account.created_at,
            account_plan: auth.plan?.name ?? null,
            account_plan_slug: auth.plan?.slug ?? null,
            account_subscribed: Boolean(auth.hasActiveSubscription),
        });
    }

    if (auth.currentWorkspace) {
        window.dataLayer.push({
            workspace_id: auth.currentWorkspace.id,
            workspace_name: auth.currentWorkspace.name,
            workspace_count: auth.workspaces?.length ?? 0,
        });
    }
};
