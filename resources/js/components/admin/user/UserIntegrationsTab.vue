<script setup lang="ts">
import { IconKey, IconPhoto, IconWebhook } from '@tabler/icons-vue';
import Canva from '@thesvg/vue/canva';
import Giphy from '@thesvg/vue/giphy';
import GoogleDrive from '@thesvg/vue/google-drive-2026';
import GooglePhotos from '@thesvg/vue/google-photos';
import McpModelContextProtocol from '@thesvg/vue/mcp-model-context-protocol';
import Unsplash from '@thesvg/vue/unsplash';
import { computed, type Component } from 'vue';

import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import date from '@/date';
import { filled } from '@/lib/helpers';
import { mcpAppLogos } from '@/lib/mcpClients';
import type { AdminUserIntegrationsData } from '@/types/admin';

type BadgeVariant = 'success' | 'warning' | 'destructive' | 'secondary';

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

const created = (value: string | null): string => {
    if (filled(value)) {
        return `Created ${date.formatDate(value)}`;
    }

    return '';
};

const orUnnamed = (value: string | null): string => {
    if (filled(value)) {
        return value;
    }

    return 'Unnamed';
};

const humanize = (value: string | null): string => {
    if (!filled(value)) {
        return '—';
    }

    const words = value.replaceAll('_', ' ');

    return `${words.charAt(0).toUpperCase()}${words.slice(1)}`;
};

/** The AI app's own mark when its name is one we know, else the MCP mark. */
const mcpLogo = (client: string | null): { logo: Component; logoClass?: string } => {
    const name = (client ?? '').toLowerCase();
    const brand = mcpAppLogos.find((app) => name.includes(app.name));

    if (filled(brand)) {
        return { logo: brand.logo, logoClass: brand.logoClass };
    }

    return { logo: McpModelContextProtocol };
};

const mediaSource = (source: string | null): { label: string; logo: Component | null } => {
    const known = MEDIA_SOURCES[source ?? ''];

    if (filled(known)) {
        return known;
    }

    return { label: humanize(source), logo: null };
};

const webhookBadge = (status: string | null): { label: string; variant: BadgeVariant } => {
    const badge = WEBHOOK_STATUS_BADGES[status ?? ''];

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
</script>

<template>
    <div class="flex flex-col gap-10" data-testid="admin-user-integrations">
        <SettingsSection title="API keys">
            <p
                v-if="!hasApiKeys"
                class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground"
            >
                No API keys.
            </p>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="key in data.api_keys"
                    :key="key.id"
                    :icon="IconKey"
                >
                    <p class="truncate text-sm font-emphasis text-foreground">
                        {{ orUnnamed(key.name) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ key.workspace_name }} · {{ created(key.created_at) }} ·
                        {{ lastUsed(key.last_used_at) }}
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
            <p
                v-if="!hasMcpApps"
                class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground"
            >
                No AI app connected.
            </p>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="app in data.mcp_apps"
                    :key="app.id"
                    :data-testid="`admin-user-mcp-${app.id}`"
                >
                    <template #media>
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            <component
                                :is="mcpLogo(app.client).logo"
                                aria-hidden="true"
                                :class="['size-5 text-foreground', mcpLogo(app.client).logoClass]"
                            />
                        </span>
                    </template>
                    <p class="truncate text-sm font-emphasis text-foreground">
                        {{ orUnnamed(app.client) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ app.workspace_name }} · {{ created(app.created_at) }} ·
                        {{ lastUsed(app.last_used_at) }}
                    </p>
                </SettingsListRow>
            </ul>
        </SettingsSection>

        <SettingsSection
            title="Webhooks"
            description="In the workspaces this user is in."
        >
            <p
                v-if="!hasWebhooks"
                class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground"
            >
                No webhooks.
            </p>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="webhook in data.webhooks"
                    :key="webhook.id"
                    :icon="IconWebhook"
                >
                    <p class="truncate text-sm font-emphasis text-foreground">
                        {{ webhook.endpoint }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ webhook.workspace_name }} · {{ eventsLabel(webhook.events) }}
                        · {{ failuresLabel(webhook.consecutive_failures) }}
                    </p>
                    <template #actions>
                        <Badge :variant="webhookBadge(webhook.status).variant">{{
                            webhookBadge(webhook.status).label
                        }}</Badge>
                    </template>
                </SettingsListRow>
            </ul>
        </SettingsSection>

        <SettingsSection title="Media sources">
            <p
                v-if="!hasMediaSources"
                class="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground"
            >
                No media source connected.
            </p>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="source in data.media_sources"
                    :key="source.id"
                    :data-testid="`admin-user-media-source-${source.source}`"
                >
                    <template #media>
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            <component
                                :is="mediaSource(source.source).logo"
                                v-if="mediaSource(source.source).logo"
                                aria-hidden="true"
                                class="size-5"
                            />
                            <IconPhoto v-else class="size-5 text-muted-foreground" />
                        </span>
                    </template>
                    <p class="truncate text-sm font-emphasis text-foreground">
                        {{ mediaSource(source.source).label }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ created(source.connected_at) }}
                    </p>
                </SettingsListRow>
            </ul>
        </SettingsSection>
    </div>
</template>
