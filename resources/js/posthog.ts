import type { Page } from '@inertiajs/core';
import posthog from 'posthog-js';

import type { Auth, Usage } from './types';

const apiKey = import.meta.env.VITE_POSTHOG_API_KEY as string | undefined;
const host = import.meta.env.VITE_POSTHOG_HOST as string | undefined;
// Vite stringifies env vars, so explicit equality with 'true' avoids the
// gotcha where 'false' would be truthy. Mirrors backend `POSTHOG_ENABLED`.
const enabled = import.meta.env.VITE_POSTHOG_ENABLED === 'true' && !!apiKey;

export const initializePostHog = (): void => {
    if (!enabled) {
        return;
    }

    posthog.init(apiKey as string, {
        api_host: host || 'https://us.i.posthog.com',
        ui_host: 'https://us.posthog.com',
        capture_pageview: false,
        capture_pageleave: true,
        cross_subdomain_cookie: true,
        enable_recording_console_log: true,
        session_recording: {
            maskAllInputs: true,
            maskTextSelector: '.ph-no-capture',
        },
    });
};

/**
 * Identify the current person and refresh the dual group context (account +
 * workspace) using the metrics already shipped by Inertia in shared props.
 *
 * Called once at boot and on every Inertia navigation so:
 * - Counts on the `account` group (workspaces, social accounts, posts,
 *   members, credits) stay reactive without per-domain backend triggers.
 * - Workspace switches re-attach the right `workspace` group (the
 *   navigation event carries the fresh `auth.currentWorkspace`).
 *
 * Hierarchy mirrors the backend `app/Jobs/PostHog/SyncUser.php`:
 * person → User, group `account` → billing/plan parent,
 * group `workspace` → collaboration child (carries `account_id`).
 */
export const syncPostHogContext = (page: Page): void => {
    if (!enabled) {
        return;
    }

    const auth = page.props.auth as Auth | undefined;
    const usage = page.props.usage as Usage | null | undefined;

    if (!auth?.user) {
        return;
    }

    posthog.identify(auth.user.id, {
        $email: auth.user.email,
        $name: auth.user.name,
    });

    if (auth.account) {
        posthog.group('account', auth.account.id, {
            name: auth.account.name,
            workspaces_count: usage?.workspaceCount,
            social_accounts_count: usage?.socialAccountCount,
            members_count: usage?.memberCount,
            posts_count: usage?.postCount,
            credits_used: usage?.creditsUsed,
        });
    }

    if (auth.currentWorkspace) {
        posthog.group('workspace', auth.currentWorkspace.id, {
            name: auth.currentWorkspace.name,
            account_id: auth.account?.id,
        });
    }
};

/**
 * Capture an Inertia-driven pageview. The base SDK is configured with
 * `capture_pageview: false`, so this needs to be invoked explicitly on
 * every navigation (and once at boot for the first page).
 */
export const capturePageview = (): void => {
    if (!enabled) {
        return;
    }
    posthog.capture('$pageview', { $current_url: window.location.href });
};

export default posthog;
