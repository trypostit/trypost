<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import {
    IconArrowUpRight,
    IconDotsVertical,
    IconPlugConnected,
    IconPlus,
    IconTrash,
} from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import McpConnectDialog from '@/components/mcp/McpConnectDialog.vue';
import McpEmptyIllustration from '@/components/mcp/McpEmptyIllustration.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { DOCS_URL } from '@/lib/docs';
import { mcpClients, otherMcpClient, type McpClient } from '@/lib/mcpClients';
import { disconnect as mcpDisconnect } from '@/routes/app/mcp';

interface ConnectedClient {
    client_id: string;
    name: string;
    can_disconnect: boolean;
    last_used_at: string | null;
}

const props = defineProps<{
    mcpUrl: string;
    connectedClients: ConnectedClient[];
}>();

const docsUrl = `${DOCS_URL}/ai/introduction`;

/** Shows the Claude or ChatGPT mark when the connected app is one of them. */
const connectedRows = computed(() =>
    props.connectedClients.map((client) => ({
        ...client,
        app:
            mcpClients.find((app) =>
                client.name.toLowerCase().includes(app.label.toLowerCase()),
            ) ?? null,
    })),
);
const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

usePoll(1000, {
    only: ['connectedClients'],
});

const confirmDisconnect = (client: ConnectedClient, keyword: string): void => {
    deleteModal.value?.open({
        url: mcpDisconnect.url({ client: client.client_id }),
        confirmText: keyword,
    });
};

const connectableClients: McpClient[] = [...mcpClients, otherMcpClient];

const activeClient = ref<McpClient>(mcpClients[0]);
const dialogOpen = ref(false);

const connectWith = (client: McpClient): void => {
    activeClient.value = client;
    dialogOpen.value = true;
};

type McpTab = 'connected' | 'connect';

const tab = ref<McpTab>('connected');

const showConnectTab = (): void => {
    tab.value = 'connect';
};

const connectedCount = computed((): number => props.connectedClients.length);

/**
 * The poll notices the new connection while the user is in the other browser
 * tab: close the steps and show it in the connected list.
 */
const showNewConnection = (count: number, previous: number): void => {
    if (count > previous) {
        dialogOpen.value = false;
        tab.value = 'connected';
    }
};

watch(connectedCount, showNewConnection);

</script>

<template>
    <Head :title="$t('mcp.title')" />

    <SettingsLayout
        :title="$t('mcp.title')"
        :description="$t('mcp.subtitle')"
    >
        <Tabs v-model="tab" class="flex flex-col gap-6">
            <TabsList variant="line">
                <TabsTrigger value="connected" data-testid="mcp-tab-connected">
                    {{ $t('mcp.tabs.connected') }}
                </TabsTrigger>
                <TabsTrigger value="connect" data-testid="mcp-tab-connect">
                    {{ $t('mcp.tabs.connect') }}
                </TabsTrigger>
            </TabsList>

            <TabsContent value="connected" class="flex flex-col gap-4">
                <p
                    v-if="connectedClients.length > 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ $t('mcp.connected_description') }}
                </p>
                <ul
                    v-if="connectedClients.length > 0"
                    class="flex flex-col gap-2"
                >
                    <SettingsListRow
                        v-for="client in connectedRows"
                        :key="client.client_id"
                        :icon="client.app ? undefined : IconPlugConnected"
                        :data-testid="`mcp-connected-client-${client.client_id}`"
                    >
                        <template v-if="client.app" #media>
                            <span
                                class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                            >
                                <img
                                    :src="client.app.logo"
                                    alt=""
                                    :class="['size-5 object-contain', client.app.logoClass]"
                                    :data-testid="`mcp-connected-logo-${client.app.id}`"
                                />
                            </span>
                        </template>
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
                                        @click="
                                            confirmDisconnect(
                                                client,
                                                $t('channels.disconnect_modal.keyword'),
                                            )
                                        "
                                    >
                                        <IconTrash class="size-4" />
                                        {{ $t('mcp.disconnect') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </template>
                    </SettingsListRow>
                </ul>

                <EmptyState
                    v-else
                    class="py-6"
                    :title="$t('mcp.connected_empty_title')"
                    :description="$t('mcp.connected_empty')"
                    data-testid="mcp-connected-empty"
                >
                    <template #illustration>
                        <McpEmptyIllustration />
                    </template>
                    <template #action>
                        <Button
                            type="button"
                            data-testid="mcp-connected-empty-connect"
                            @click="showConnectTab"
                        >
                            <IconPlus class="size-4" />
                            {{ $t('mcp.tabs.connect') }}
                        </Button>
                    </template>
                </EmptyState>
            </TabsContent>

            <TabsContent value="connect" class="flex flex-col gap-4">
                <ul class="flex flex-col gap-2">
                    <SettingsListRow
                        v-for="client in connectableClients"
                        :key="client.id"
                        :data-testid="`mcp-primary-client-${client.id}`"
                    >
                        <template #media>
                            <span
                                class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                            >
                                <img
                                    :src="client.logo"
                                    alt=""
                                    :class="['size-5 object-contain', client.logoClass]"
                                />
                            </span>
                        </template>
                        <p
                            class="truncate text-sm leading-tight font-emphasis text-foreground"
                        >
                            {{ client.labelKey ? $t(client.label) : client.label }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                client.labelKey
                                    ? $t('mcp.other_hint')
                                    : $t('mcp.connect_hint')
                            }}
                        </p>
                        <template #actions>
                            <Button
                                type="button"
                                variant="outline"
                                class="shrink-0"
                                :data-testid="`mcp-connect-${client.id}`"
                                @click="connectWith(client)"
                            >
                                {{
                                    client.labelKey
                                        ? $t('mcp.connect_other')
                                        : $t('mcp.connect', { client: client.label })
                                }}
                            </Button>
                        </template>
                    </SettingsListRow>

                </ul>

                <p
                    class="text-sm text-muted-foreground"
                    data-testid="mcp-help"
                >
                    {{ $t('mcp.help') }}
                    <a
                        :href="docsUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-0.5 text-primary-text underline underline-offset-2 hover:text-primary-text-hover"
                        data-testid="mcp-help-link"
                    >
                        {{ $t('mcp.help_link') }}
                        <IconArrowUpRight class="size-3.5 rtl:-scale-x-100" />
                    </a>
                </p>
            </TabsContent>
        </Tabs>

        <McpConnectDialog
            v-model:open="dialogOpen"
            :client="activeClient"
            :mcp-url="mcpUrl"
        />

        <ConfirmDeleteModal
            ref="deleteModal"
            method="delete"
            :title="$t('mcp.disconnect_title')"
            :description="$t('mcp.disconnect_confirm')"
            :action="$t('mcp.disconnect')"
        />
    </SettingsLayout>
</template>
