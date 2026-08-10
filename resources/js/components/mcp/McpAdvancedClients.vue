<script setup lang="ts">
import { IconChevronDown, IconCopy } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{
    mcpUrl: string;
}>();

type AdvancedMcpClientKey = 'cursor' | 'vscode' | 'claude_code' | 'other';
type McpConfigRoot = 'servers' | 'mcpServers';

interface AdvancedMcpClient {
    key: AdvancedMcpClientKey;
    name: string;
    description: string;
    logo: string;
    tileClass: string;
    httpType: boolean;
    configRoot: McpConfigRoot;
}

const advancedClients: AdvancedMcpClient[] = [
    {
        key: 'cursor',
        name: 'mcp.clients.cursor_name',
        description: 'mcp.clients.cursor',
        logo: '/images/ai/cursor.svg',
        tileClass: 'bg-white -rotate-1',
        httpType: false,
        configRoot: 'mcpServers',
    },
    {
        key: 'vscode',
        name: 'mcp.clients.vscode_name',
        description: 'mcp.clients.vscode',
        logo: '/images/ai/vscode.svg',
        tileClass: 'bg-sky-100 rotate-2',
        httpType: true,
        configRoot: 'servers',
    },
    {
        key: 'claude_code',
        name: 'mcp.clients.claude_code_name',
        description: 'mcp.clients.claude_code',
        logo: '/images/ai/claude.svg',
        tileClass: 'bg-orange-100 rotate-1',
        httpType: true,
        configRoot: 'mcpServers',
    },
    {
        key: 'other',
        name: 'mcp.clients.other_name',
        description: 'mcp.clients.other',
        logo: '/images/ai/other-clients.svg',
        tileClass: 'bg-amber-100 -rotate-2',
        httpType: false,
        configRoot: 'mcpServers',
    },
];

const openClient = ref<AdvancedMcpClientKey | ''>('');
const connectorName = computed(() => trans('mcp.connector_name'));
const copiedMessage = computed(() => trans('mcp.copied'));

const configSnippet = (client: AdvancedMcpClient): string => {
    const server = client.httpType
        ? { type: 'http', url: props.mcpUrl }
        : { url: props.mcpUrl };

    return JSON.stringify(
        { [client.configRoot]: { [connectorName.value]: server } },
        null,
        2,
    );
};

const setClientOpen = (client: AdvancedMcpClientKey, open: boolean): void => {
    openClient.value = open ? client : '';
};

const copy = (value: string): void => {
    copyToClipboard(value, copiedMessage.value);
};
</script>

<template>
    <div class="space-y-4">
        <HeadingSmall
            :title="$t('mcp.other_clients_title')"
            :description="$t('mcp.other_clients_description')"
        />

        <div class="flex flex-col gap-4 pb-1">
            <Collapsible
                v-for="client in advancedClients"
                :key="client.key"
                :open="openClient === client.key"
                class="overflow-hidden rounded-xl border-2 border-foreground bg-card shadow-2xs"
                @update:open="(open) => setClientOpen(client.key, open)"
            >
                <CollapsibleTrigger
                    class="flex w-full items-start justify-between gap-4 rounded-md px-5 py-4 text-left text-sm font-medium outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50"
                    :data-testid="`mcp-advanced-client-${client.key}`"
                >
                    <span class="flex items-center gap-4 text-start">
                        <span
                            class="inline-flex size-12 shrink-0 items-center justify-center rounded-xl border-2 border-foreground shadow-sm"
                            :class="client.tileClass"
                        >
                            <img
                                :src="client.logo"
                                :alt="$t(client.name)"
                                class="size-6 object-contain"
                            />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-bold">
                                {{ $t(client.name) }}
                            </span>
                            <span
                                class="mt-0.5 block text-sm text-foreground/70"
                            >
                                {{ $t(client.description) }}
                            </span>
                        </span>
                    </span>
                    <IconChevronDown
                        class="pointer-events-none size-4 shrink-0 translate-y-0.5 text-muted-foreground transition-transform duration-200 motion-reduce:transition-none"
                        :class="openClient === client.key ? 'rotate-180' : ''"
                    />
                </CollapsibleTrigger>

                <CollapsibleContent
                    class="overflow-hidden px-5 pt-1 pb-5 text-sm data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down motion-reduce:data-[state=closed]:animate-none motion-reduce:data-[state=open]:animate-none"
                >
                    <div class="space-y-5">
                        <p class="text-sm font-medium text-foreground/70">
                            {{ $t('mcp.step_add') }}
                        </p>

                        <div class="grid gap-2">
                            <p class="font-bold">
                                {{ $t('mcp.name_label') }}
                            </p>
                            <div
                                class="flex items-center gap-2 rounded-xl border-2 border-foreground bg-background p-2 shadow-2xs"
                            >
                                <code
                                    dir="ltr"
                                    class="min-w-0 flex-1 truncate px-2 text-left font-mono text-sm"
                                >
                                    {{ connectorName }}
                                </code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="size-9 shrink-0"
                                    :aria-label="`${$t('common.actions.copy')} ${$t('mcp.name_label')}`"
                                    @click="copy(connectorName)"
                                >
                                    <IconCopy class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <p class="font-bold">
                                {{ $t('mcp.url_label') }}
                            </p>
                            <div
                                class="flex items-center gap-2 rounded-xl border-2 border-foreground bg-background p-2 shadow-2xs"
                            >
                                <code
                                    dir="ltr"
                                    class="min-w-0 flex-1 truncate px-2 text-left font-mono text-sm"
                                >
                                    {{ mcpUrl }}
                                </code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="size-9 shrink-0"
                                    :aria-label="`${$t('common.actions.copy')} ${$t('mcp.url_label')}`"
                                    @click="copy(mcpUrl)"
                                >
                                    <IconCopy class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <p class="font-bold">
                                {{ $t('mcp.config_label') }}
                            </p>
                            <div class="relative">
                                <pre
                                    dir="ltr"
                                    class="overflow-x-auto rounded-xl border-2 border-foreground bg-background p-3 pe-14 text-left font-mono text-xs shadow-2xs"
                                    :data-testid="`mcp-config-${client.key}`"
                                ><code>{{ configSnippet(client) }}</code></pre>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="absolute inset-e-2 top-2 size-9"
                                    :aria-label="`${$t('common.actions.copy')} ${$t('mcp.config_label')}`"
                                    @click="copy(configSnippet(client))"
                                >
                                    <IconCopy class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>
        </div>
    </div>
</template>
