import type { Auth } from './types';

/**
 * Push app + identity context to GTM's dataLayer on every page load. The
 * pushes are no-ops when GTM isn't configured (the array still exists in
 * memory; nothing reads it). Self-hosted instances without GTM_ID set
 * incur zero error noise.
 *
 * Billing is account-scoped (not workspace-scoped) in trypost, so the
 * plan field is emitted under `account_plan`.
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
    });

    if (auth.account) {
        window.dataLayer.push({
            account_id: auth.account.id,
            account_plan: auth.plan?.name ?? null,
        });
    }

    if (auth.currentWorkspace) {
        window.dataLayer.push({
            workspace_id: auth.currentWorkspace.id,
        });
    }
};
