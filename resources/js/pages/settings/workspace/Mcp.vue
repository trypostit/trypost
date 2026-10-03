<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import {
    IconDotsVertical,
    IconExternalLink,
    IconPlugConnected,
    IconTrash,
} from '@tabler/icons-vue';
import { ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import McpAdvancedClients from '@/components/mcp/McpAdvancedClients.vue';
import McpPrimarySetup from '@/components/mcp/McpPrimarySetup.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsRow from '@/components/settings/SettingsRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
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

    <SettingsLayout
        :title="$t('mcp.title')"
        :description="$t('mcp.subtitle')"
    >
        <div class="flex flex-col gap-10">
            <McpPrimarySetup
                :mcp-url="mcpUrl"
                :copied-message="$t('mcp.copied')"
            />

            <Separator />

            <McpAdvancedClients :mcp-url="mcpUrl" />

            <Separator />

            <SettingsSection
                :title="$t('mcp.connected_title')"
                :description="$t('mcp.connected_description')"
            >
                <ul
                    v-if="connectedClients.length > 0"
                    class="flex flex-col gap-2"
                >
                    <SettingsListRow
                        v-for="client in connectedClients"
                        :key="client.client_id"
                        :icon="IconPlugConnected"
                        :data-testid="`mcp-connected-client-${client.client_id}`"
                    >
                        <p
                            class="truncate text-sm leading-tight font-emphasis text-foreground"
                        >
                            {{ client.name }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('mcp.last_used') }}:
                            {{
                                client.last_used_at
                                    ? date.diffForHumans(client.last_used_at)
                                    : $t('mcp.never')
                            }}
                        </p>
                        <template v-if="client.can_disconnect" #actions>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="shrink-0 text-muted-foreground data-[state=open]:bg-accent"
                                        :aria-label="client.name"
                                        :data-testid="`mcp-connected-menu-${client.client_id}`"
                                    >
                                        <IconDotsVertical class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        variant="destructive"
                                        :data-testid="`mcp-disconnect-${client.client_id}`"
                                        @click="confirmDisconnect(client)"
                                    >
                                        <IconTrash class="size-4" />
                                        {{ $t('mcp.disconnect') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </template>
                    </SettingsListRow>
                </ul>

                <div
                    v-else
                    class="rounded-xl border border-dashed border-border-strong"
                    data-testid="mcp-connected-empty"
                >
                    <EmptyState
                        :icon="IconPlugConnected"
                        :title="$t('mcp.connected_empty_title')"
                        :description="$t('mcp.connected_empty')"
                    />
                </div>
            </SettingsSection>

            <Separator />

            <SettingsRow
                :title="$t('mcp.documentation_title')"
                :description="$t('mcp.documentation_description')"
            >
                <Button as="a" variant="outline" target="_blank" :href="docsUrl">
                    <IconExternalLink class="size-4" />
                    {{ $t('mcp.view_docs') }}
                </Button>
            </SettingsRow>
        </div>

        <ConfirmDeleteModal
            ref="deleteModal"
            method="delete"
            :title="$t('mcp.disconnect_title')"
            :description="$t('mcp.disconnect_confirm')"
            :action="$t('mcp.disconnect')"
        />
    </SettingsLayout>
</template>
