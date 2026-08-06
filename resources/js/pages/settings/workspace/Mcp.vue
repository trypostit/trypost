<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { IconExternalLink } from '@tabler/icons-vue';
import { ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import McpAdvancedClients from '@/components/mcp/McpAdvancedClients.vue';
import McpPrimarySetup from '@/components/mcp/McpPrimarySetup.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import { Button } from '@/components/ui/button';
import { useWorkspaceSettingsTabs } from '@/composables/useWorkspaceSettingsTabs';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { disconnect as mcpDisconnect } from '@/routes/app/mcp';

interface ConnectedClient {
    client_id: string;
    name: string;
    can_disconnect: boolean;
    last_used_at: string | null;
}

defineProps<{
    mcpUrl: string;
    connectedClients: ConnectedClient[];
}>();

const docsUrl = 'https://docs.trypost.it/ai/introduction';
const tabs = useWorkspaceSettingsTabs();
const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

usePoll(1000, {
    only: ['connectedClients'],
});

const confirmDisconnect = (client: ConnectedClient): void => {
    deleteModal.value?.open({
        url: mcpDisconnect.url({ client: client.client_id }),
        confirmText: client.name,
    });
};
</script>

<template>
    <Head :title="$t('mcp.title')" />

    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <PageHeader
                :title="$t('settings.hub.title')"
                :description="$t('settings.hub.description')"
            />

            <SettingsTabsNav :tabs="tabs" active="mcp" />

            <div class="flex max-w-3xl flex-col gap-10">
                <HeadingSmall
                    :title="$t('mcp.title')"
                    :description="$t('mcp.subtitle')"
                />

                <section class="space-y-6">
                    <McpPrimarySetup
                        :mcp-url="mcpUrl"
                        :copied-message="$t('mcp.copied')"
                    />
                    <McpAdvancedClients :mcp-url="mcpUrl" />
                </section>

                <section class="space-y-4">
                    <HeadingSmall
                        :title="$t('mcp.connected_title')"
                        :description="$t('mcp.connected_description')"
                    />

                    <div
                        v-if="connectedClients.length === 0"
                        class="rounded-xl border-2 border-dashed border-foreground/25 bg-card/40 px-4 py-6 text-center text-sm font-medium text-foreground/60"
                        data-testid="mcp-connected-empty"
                    >
                        {{ $t('mcp.connected_empty') }}
                    </div>

                    <div v-else class="grid gap-3">
                        <div
                            v-for="client in connectedClients"
                            :key="client.client_id"
                            class="flex items-center justify-between gap-4 rounded-xl border-2 border-foreground bg-card px-4 py-3 shadow-2xs"
                            :data-testid="`mcp-connected-client-${client.client_id}`"
                        >
                            <div class="min-w-0">
                                <p
                                    class="flex items-center gap-2 truncate text-sm font-bold"
                                >
                                    <span
                                        class="size-2 shrink-0 rounded-full bg-emerald-500"
                                        aria-hidden="true"
                                    />
                                    <span class="truncate">{{
                                        client.name
                                    }}</span>
                                </p>
                                <p
                                    class="text-xs font-medium text-foreground/60"
                                >
                                    {{ $t('mcp.last_used') }}:
                                    {{
                                        client.last_used_at
                                            ? date.diffForHumans(
                                                  client.last_used_at,
                                              )
                                            : $t('mcp.never')
                                    }}
                                </p>
                            </div>
                            <Button
                                v-if="client.can_disconnect"
                                variant="outline"
                                size="sm"
                                @click="confirmDisconnect(client)"
                            >
                                {{ $t('mcp.disconnect') }}
                            </Button>
                        </div>
                    </div>
                </section>

                <section class="space-y-4">
                    <HeadingSmall
                        :title="$t('mcp.documentation_title')"
                        :description="$t('mcp.documentation_description')"
                    />
                    <Button
                        as="a"
                        variant="outline"
                        size="sm"
                        target="_blank"
                        :href="docsUrl"
                    >
                        <IconExternalLink class="size-4" />
                        {{ $t('mcp.view_docs') }}
                    </Button>
                </section>
            </div>
        </div>

        <ConfirmDeleteModal
            ref="deleteModal"
            method="delete"
            :title="$t('mcp.disconnect_title')"
            :description="$t('mcp.disconnect_confirm')"
            :action="$t('mcp.disconnect')"
        />
    </AppLayout>
</template>
