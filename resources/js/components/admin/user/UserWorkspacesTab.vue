<script setup lang="ts">
import { IconBuilding } from '@tabler/icons-vue';
import { computed } from 'vue';

import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { channelDisplayName, channelStatus } from '@/lib/adminChannels';
import { countLabel } from '@/lib/adminFormat';
import { featureLabel } from '@/lib/adminPermissions';
import { filled } from '@/lib/helpers';
import { formatFullNumber } from '@/lib/utils';
import type {
    AdminUserChannel,
    AdminUserWorkspaceDetail,
    AdminUserWorkspacesData,
} from '@/types/admin';

const props = defineProps<{
    data: AdminUserWorkspacesData;
}>();

const channelCount = computed(() =>
    Object.values(props.data.channels_by_network).reduce((sum, count) => sum + count, 0),
);

/** One chip per network, most connected first: its logo, count and name. */
const networkChips = computed(() =>
    Object.entries(props.data.channels_by_network).map(([network, count]) => ({
        network,
        count,
        label: getPlatformLabel(network),
    })),
);

const hasChannelsConnected = computed(() => filled(networkChips.value));

const channelsTotal = computed((): string => countLabel(channelCount.value, 'channel', 'channels'));

const hasWorkspaces = computed(() => filled(props.data.workspaces));
const hasInvites = computed(() => filled(props.data.pending_invites));

const role = (workspace: AdminUserWorkspaceDetail): string => {
    if (workspace.is_owner) {
        return 'Owner';
    }

    if (workspace.is_admin) {
        return 'Admin';
    }

    if (workspace.requires_approval) {
        return 'Member, needs approval';
    }

    return 'Member';
};

const channelName = (channel: AdminUserChannel): string => channelDisplayName(channel.name, channel.platform);

const lastChecked = (channel: AdminUserChannel): string => {
    if (filled(channel.last_verified_at)) {
        return `Checked ${date.diffForHumans(channel.last_verified_at)}`;
    }

    return 'Never checked';
};

/** What the channel's connect did not grant, as the Networks page names the features. */
const missingPermissionsLabel = (channel: AdminUserChannel): string | null => {
    if (filled(channel.missing_permissions)) {
        return `Missing: ${channel.missing_permissions.map(featureLabel).join(', ')}`;
    }

    return null;
};

const workspaceDescription = (workspace: AdminUserWorkspaceDetail): string =>
    `${role(workspace)} · created ${date.formatDate(workspace.created_at)}`;

/** Each workspace with its channels read once: name, last check, missing scopes and status. */
const workspaceRows = computed(() =>
    props.data.workspaces.map((workspace) => ({
        ...workspace,
        description: workspaceDescription(workspace),
        hasChannels: filled(workspace.channels),
        channels: workspace.channels.map((channel) => ({
            id: channel.id,
            platform: channel.platform,
            name: channelName(channel),
            lastChecked: lastChecked(channel),
            missingPermissions: missingPermissionsLabel(channel),
            badge: channelStatus(channel.status),
        })),
    })),
);
</script>

<template>
    <div class="flex flex-col gap-10" data-testid="admin-user-workspaces">
        <SettingsSection title="Connected channels" :description="channelsTotal">
            <AdminEmptyState v-if="!hasChannelsConnected">No channels connected.</AdminEmptyState>
            <ul
                v-else
                class="flex flex-wrap gap-2"
                data-testid="admin-user-channels-by-network"
            >
                <li
                    v-for="chip in networkChips"
                    :key="chip.network"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-card py-1.5 ps-1.5 pe-3"
                    :data-testid="`admin-user-network-${chip.network}`"
                >
                    <PlatformLogo :platform="chip.network" size="xs" />
                    <span class="text-sm font-emphasis text-foreground tabular-nums">{{
                        formatFullNumber(chip.count)
                    }}</span>
                    <span class="text-sm text-muted-foreground">{{ chip.label }}</span>
                </li>
            </ul>
        </SettingsSection>

        <AdminEmptyState v-if="!hasWorkspaces">This user is not in any workspace.</AdminEmptyState>

        <SettingsSection
            v-for="workspace in workspaceRows"
            :key="workspace.id"
            :title="workspace.name"
            :description="workspace.description"
        >
            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
                :data-testid="`admin-user-workspace-${workspace.id}`"
            >
                <p
                    class="border-b border-border bg-muted/60 px-5 py-2.5 text-xs font-medium text-muted-foreground sm:px-6"
                >
                    Channels
                </p>
                <AdminEmptyState v-if="!workspace.hasChannels" variant="inset">
                    No channels connected.
                </AdminEmptyState>
                <ul class="divide-y divide-border">
                    <li
                        v-for="channel in workspace.channels"
                        :key="channel.id"
                        class="flex flex-wrap items-center gap-3 px-5 py-3 sm:px-6"
                    >
                        <PlatformLogo :platform="channel.platform" size="xs" />
                        <div class="grid min-w-0 flex-1">
                            <span class="truncate text-sm font-emphasis text-foreground">{{
                                channel.name
                            }}</span>
                            <span class="truncate text-xs text-muted-foreground">{{
                                channel.lastChecked
                            }}</span>
                        </div>
                        <Badge
                            v-if="channel.missingPermissions"
                            variant="warning"
                            :data-testid="`admin-user-channel-permissions-${channel.id}`"
                            >{{ channel.missingPermissions }}</Badge
                        >
                        <Badge :variant="channel.badge.variant">{{ channel.badge.label }}</Badge>
                    </li>
                </ul>
                <p
                    class="border-y border-border bg-muted/60 px-5 py-2.5 text-xs font-medium text-muted-foreground sm:px-6"
                >
                    Members
                </p>
                <ul class="divide-y divide-border">
                    <li
                        v-for="member in workspace.members"
                        :key="member.id"
                        class="flex items-center gap-3 px-5 py-3 sm:px-6"
                    >
                        <Avatar
                            :src="null"
                            :name="member.name"
                            class="size-7 shrink-0 rounded-lg"
                            fallback-class="bg-primary-subtle text-primary-text text-xs font-medium"
                        />
                        <div class="grid min-w-0 flex-1">
                            <span class="truncate text-sm text-foreground">{{ member.name }}</span>
                            <span class="truncate text-xs text-muted-foreground">{{
                                member.email
                            }}</span>
                        </div>
                        <Badge v-if="member.is_owner" variant="secondary">Owner</Badge>
                    </li>
                </ul>
            </div>
        </SettingsSection>

        <SettingsSection
            v-if="hasInvites"
            title="Pending invitations"
            description="Sent from the account they own, not accepted yet."
        >
            <ul class="flex flex-col gap-2">
                <li
                    v-for="invite in data.pending_invites"
                    :key="invite.id"
                    class="flex items-center gap-3 rounded-xl border border-border bg-card p-4"
                >
                    <IconBuilding class="size-4 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate text-sm text-foreground">{{
                        invite.email
                    }}</span>
                    <Badge v-if="invite.is_admin" variant="secondary">Admin</Badge>
                </li>
            </ul>
        </SettingsSection>
    </div>
</template>
