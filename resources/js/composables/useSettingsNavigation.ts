import { usePage } from '@inertiajs/vue3';
import {
    IconLayoutGrid,
    IconBell,
    IconCoin,
    IconCode,
    IconSignature,
    IconLock,
    IconPlugConnected,
    IconSettings,
    IconAdjustmentsHorizontal,
    IconTag,
    IconUser,
    IconUsers,
    IconWebhook,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { members } from '@/routes/app';
import { index as apiKeys } from '@/routes/app/api-keys';
import { edit as authenticationEdit } from '@/routes/app/authentication';
import { index as billing } from '@/routes/app/billing';
import { index as labels } from '@/routes/app/labels';
import { index as mcp } from '@/routes/app/mcp';
import { preferences as notificationPreferences } from '@/routes/app/notifications';
import { edit as profileEdit } from '@/routes/app/profile';
import { preferences } from '@/routes/app/settings';
import { index as signatures } from '@/routes/app/signatures';
import { index as webhooks } from '@/routes/app/webhooks';
import { channels, settings as workspaceSettings } from '@/routes/app/workspace';
import type { NavItem } from '@/types';

export interface SettingsNavGroup {
    key: string;
    label: string;
    items: (NavItem & { name: string; count?: number })[];
}

export const useSettingsNavigation = () => {
    const page = usePage();
    const {
        canManageWorkspace,
        canCreatePost,
        canManageAccounts,
        canManageWebhooks,
        canManageBilling,
    } = useWorkspaceAbilities();

    const hasWorkspace = computed(() =>
        Boolean(page.props.auth?.currentWorkspace),
    );
    const selfHosted = computed(() => Boolean(page.props.selfHosted));
    const channelCount = computed(
        () => ((page.props.channels as unknown[] | undefined) ?? []).length,
    );

    const item = (
        name: string,
        href: string,
        icon: NavItem['icon'],
        visible: boolean,
        count?: number,
    ) =>
        visible
            ? [
                  {
                      name,
                      title: trans(`settings.sidebar.items.${name}`),
                      href,
                      icon,
                      count,
                  },
              ]
            : [];

    return computed<SettingsNavGroup[]>(() => {
        const workspace = hasWorkspace.value;
        const groups: SettingsNavGroup[] = [
            {
                key: 'personal',
                label: trans('settings.sidebar.groups.personal'),
                items: [
                    ...item('profile', profileEdit.url(), IconUser, true),
                    ...item(
                        'preferences',
                        preferences.url(),
                        IconAdjustmentsHorizontal,
                        true,
                    ),
                    ...item(
                        'authentication',
                        authenticationEdit.url(),
                        IconLock,
                        true,
                    ),
                    ...item(
                        'notifications',
                        notificationPreferences.url(),
                        IconBell,
                        true,
                    ),
                ],
            },
            {
                key: 'workspace',
                label: trans('settings.sidebar.groups.workspace'),
                items: [
                    ...item(
                        'general',
                        workspaceSettings.url(),
                        IconSettings,
                        workspace && canManageWorkspace.value,
                    ),
                    ...item(
                        'channels',
                        channels.url(),
                        IconLayoutGrid,
                        workspace && canManageAccounts.value,
                        channelCount.value,
                    ),
                    ...item(
                        'members',
                        members.url(),
                        IconUsers,
                        workspace && canManageWorkspace.value,
                    ),
                ],
            },
            {
                key: 'content',
                label: trans('settings.sidebar.groups.content'),
                items: [
                    ...item(
                        'signatures',
                        signatures.url(),
                        IconSignature,
                        workspace && canCreatePost.value,
                    ),
                    ...item(
                        'labels',
                        labels.url(),
                        IconTag,
                        workspace && canCreatePost.value,
                    ),
                ],
            },
            {
                key: 'integrations',
                label: trans('settings.sidebar.groups.integrations'),
                items: [
                    ...item(
                        'api_keys',
                        apiKeys.url(),
                        IconCode,
                        workspace && canManageWorkspace.value,
                    ),
                    ...item('mcp', mcp.url(), IconPlugConnected, workspace),
                    ...item(
                        'webhooks',
                        webhooks.url(),
                        IconWebhook,
                        workspace && canManageWebhooks.value,
                    ),
                ],
            },
            {
                key: 'account',
                label: trans('settings.sidebar.groups.account'),
                items: [
                    ...item(
                        'billing',
                        billing.url(),
                        IconCoin,
                        canManageBilling.value && !selfHosted.value,
                    ),
                ],
            },
        ];

        return groups.filter((group) => group.items.length > 0);
    });
};
