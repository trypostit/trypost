<script setup lang="ts">
import { IconKey, IconPhoto, IconWebhook } from '@tabler/icons-vue';
import Canva from '@thesvg/vue/canva';
import Giphy from '@thesvg/vue/giphy';
import GoogleDrive from '@thesvg/vue/google-drive-2026';
import GooglePhotos from '@thesvg/vue/google-photos';
import McpModelContextProtocol from '@thesvg/vue/mcp-model-context-protocol';
import Unsplash from '@thesvg/vue/unsplash';
import { computed, type Component } from 'vue';

import AdminEmptyState from '@/components/admin/AdminEmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import date from '@/date';
import { humanize, joinFilled } from '@/lib/adminFormat';
import { filled, transform } from '@/lib/helpers';
import { mcpAppLogos } from '@/lib/mcpClients';
import type { AdminUserIntegrationsData, BadgeVariant } from '@/types/admin';

/** The media sources a user links, with the logos the composer shows. */
const MEDIA_SOURCES: Record<string, { label: string; logo: Component }> = {
    canva: { label: 'Canva', logo: Canva },
    google_drive: { label: 'Google Drive', logo: GoogleDrive },
    google_photos: { label: 'Google Photos', logo: GooglePhotos },
    unsplash: { label: 'Unsplash', logo: Unsplash },
    giphy: { label: 'Giphy', logo: Giphy },
};

const WEBHOOK_STATUS_BADGES: Record<string, { label: string; variant: BadgeVariant }> = {
    active: { label: 'Active', variant: 'success' },
    paused: { label: 'Paused', variant: 'warning' },
    disabled: { label: 'Disabled', variant: 'destructive' },
};

const props = defineProps<{
    data: AdminUserIntegrationsData;
}>();

const hasApiKeys = computed(() => filled(props.data.api_keys));
const hasMcpApps = computed(() => filled(props.data.mcp_apps));
const hasWebhooks = computed(() => filled(props.data.webhooks));
const hasMediaSources = computed(() => filled(props.data.media_sources));

const lastUsed = (value: string | null): string => {
    if (filled(value)) {
        return `Last used ${date.diffForHumans(value)}`;
    }

    return 'Never used';
};

const created = (value: string | null): string | null => {
    if (filled(value)) {
        return `Created ${date.formatDate(value)}`;
    }

    return null;
};

const orUnnamed = (value: string | null): string => {
    if (filled(value)) {
        return value;
    }

    return 'Unnamed';
};

/** The AI app's own mark when its name is one we know, else the MCP mark. */
const mcpLogo = (client: string | null): { logo: Component; logoClass?: string } => {
    const name = transform(client, (value) => value.toLowerCase(), '');
    const brand = mcpAppLogos.find((app) => name.includes(app.name));

    if (filled(brand)) {
        return { logo: brand.logo, logoClass: brand.logoClass };
    }

    return { logo: McpModelContextProtocol };
};

const mediaSource = (source: string | null): { label: string; logo: Component | null } => {
    const known = transform(source, (value) => MEDIA_SOURCES[value]);

    if (filled(known)) {
        return known;
    }

    return { label: humanize(source), logo: null };
};

const webhookBadge = (status: string | null): { label: string; variant: BadgeVariant } => {
    const badge = transform(status, (value) => WEBHOOK_STATUS_BADGES[value]);

    if (filled(badge)) {
        return badge;
    }

    return { label: humanize(status), variant: 'secondary' };
};

const eventsLabel = (events: string[]): string => {
    if (events.length === 1) {
        return '1 event';
    }

    return `${events.length} events`;
};

const failuresLabel = (failures: number): string => {
    if (failures === 0) {
        return 'No failures';
    }

    if (failures === 1) {
        return '1 failure in a row';
    }

    return `${failures} failures in a row`;
};

const apiKeys = computed(() =>
    props.data.api_keys.map((key) => ({
        id: key.id,
        name: orUnnamed(key.name),
        detail: joinFilled([key.workspace_name, created(key.created_at), lastUsed(key.last_used_at)]),
        revoked: key.revoked,
    })),
);

const mcpApps = computed(() =>
    props.data.mcp_apps.map((app) => {
        const { logo, logoClass } = mcpLogo(app.client);

        return {
            id: app.id,
            name: orUnnamed(app.client),
            logo,
            logoClass: ['size-5 text-foreground', logoClass],
            detail: joinFilled([app.workspace_name, created(app.created_at), lastUsed(app.last_used_at)]),
        };
    }),
);

const webhooks = computed(() =>
    props.data.webhooks.map((webhook) => ({
        id: webhook.id,
        endpoint: webhook.endpoint,
        detail: joinFilled([
            webhook.workspace_name,
            eventsLabel(webhook.events),
            failuresLabel(webhook.consecutive_failures),
        ]),
        badge: webhookBadge(webhook.status),
    })),
);

const mediaSources = computed(() =>
    props.data.media_sources.map((source) => ({
        id: source.id,
        testId: `admin-user-media-source-${source.source}`,
        ...mediaSource(source.source),
        connected: created(source.connected_at),
    })),
);
</script>

<template>
    <div class="flex flex-col gap-10" data-testid="admin-user-integrations">
        <SettingsSection title="API keys">
            <AdminEmptyState v-if="!hasApiKeys">No API keys.</AdminEmptyState>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow v-for="key in apiKeys" :key="key.id" :icon="IconKey">
                    <p class="truncate text-sm font-emphasis text-foreground">
                        {{ key.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ key.detail }}
                    </p>
                    <template #actions>
                        <Badge v-if="key.revoked" variant="secondary">Revoked</Badge>
                        <Badge v-else variant="success">Active</Badge>
                    </template>
                </SettingsListRow>
            </ul>
        </SettingsSection>

        <SettingsSection
            title="AI apps (MCP)"
            description="The AI apps connected to TryPost with this user's account."
        >
            <AdminEmptyState v-if="!hasMcpApps">No AI app connected.</AdminEmptyState>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="app in mcpApps"
                    :key="app.id"
                    :data-testid="`admin-user-mcp-${app.id}`"
                >
                    <template #media>
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            <component :is="app.logo" aria-hidden="true" :class="app.logoClass" />
                        </span>
                    </template>
                    <p class="truncate text-sm font-emphasis text-foreground">
                        {{ app.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ app.detail }}
                    </p>
                </SettingsListRow>
            </ul>
        </SettingsSection>

        <SettingsSection
            title="Webhooks"
            description="In the workspaces this user is in."
        >
            <AdminEmptyState v-if="!hasWebhooks">No webhooks.</AdminEmptyState>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow v-for="webhook in webhooks" :key="webhook.id" :icon="IconWebhook">
                    <p class="truncate text-sm font-emphasis text-foreground">
                        {{ webhook.endpoint }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ webhook.detail }}
                    </p>
                    <template #actions>
                        <Badge :variant="webhook.badge.variant">{{ webhook.badge.label }}</Badge>
                    </template>
                </SettingsListRow>
            </ul>
        </SettingsSection>

        <SettingsSection title="Media sources">
            <AdminEmptyState v-if="!hasMediaSources">No media source connected.</AdminEmptyState>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow v-for="source in mediaSources" :key="source.id" :data-testid="source.testId">
                    <template #media>
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            <component :is="source.logo" v-if="source.logo" aria-hidden="true" class="size-5" />
                            <IconPhoto v-else class="size-5 text-muted-foreground" />
                        </span>
                    </template>
                    <p class="truncate text-sm font-emphasis text-foreground">
                        {{ source.label }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ source.connected }}
                    </p>
                </SettingsListRow>
            </ul>
        </SettingsSection>
    </div>
</template>
