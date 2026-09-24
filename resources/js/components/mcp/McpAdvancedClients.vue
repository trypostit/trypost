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
        tileClass: 'bg-white',
        httpType: false,
        configRoot: 'mcpServers',
    },
    {
        key: 'vscode',
        name: 'mcp.clients.vscode_name',
        description: 'mcp.clients.vscode',
        logo: '/images/ai/vscode.svg',
        tileClass: 'bg-sky-100',
        httpType: true,
        configRoot: 'servers',
    },
    {
        key: 'claude_code',
        name: 'mcp.clients.claude_code_name',
        description: 'mcp.clients.claude_code',
        logo: '/images/ai/claude.svg',
        tileClass: 'bg-orange-100',
        httpType: true,
        configRoot: 'mcpServers',
    },
    {
        key: 'other',
        name: 'mcp.clients.other_name',
        description: 'mcp.clients.other',
        logo: '/images/ai/other-clients.svg',
        tileClass: 'bg-amber-100',
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

        <div class="divide-y divide-border rounded-md border border-border">
            <Collapsible
                v-for="client in advancedClients"
                :key="client.key"
                :open="openClient === client.key"
                @update:open="(open) => setClientOpen(client.key, open)"
            >
                <CollapsibleTrigger
                    class="flex w-full cursor-pointer items-center justify-between gap-4 px-4 py-3 text-left text-sm transition-colors outline-none hover:bg-accent/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50"
                    :data-testid="`mcp-advanced-client-${client.key}`"
                >
                    <span class="flex min-w-0 items-center gap-3 text-start">
                        <span
                            class="inline-flex size-9 shrink-0 items-center justify-center rounded-md border border-border"
                            :class="client.tileClass"
                        >
                            <img
                                :src="client.logo"
                                :alt="$t(client.name)"
                                class="size-5 object-contain"
                            />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-medium text-foreground">
                                {{ $t(client.name) }}
                            </span>
                            <span
                                class="mt-0.5 block text-xs text-muted-foreground"
                            >
                                {{ $t(client.description) }}
                            </span>
                        </span>
                    </span>
                    <IconChevronDown
                        class="pointer-events-none size-4 shrink-0 text-muted-foreground transition-transform duration-200 motion-reduce:transition-none"
                        :class="openClient === client.key ? 'rotate-180' : ''"
                    />
                </CollapsibleTrigger>

                <CollapsibleContent
                    class="overflow-hidden border-t border-border bg-muted/30 px-4 py-4 text-sm data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down motion-reduce:data-[state=closed]:animate-none motion-reduce:data-[state=open]:animate-none"
                >
                    <div class="space-y-4">
                        <p class="text-sm text-muted-foreground">
                            {{ $t('mcp.step_add') }}
                        </p>

                        <div class="grid gap-1.5">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ $t('mcp.name_label') }}
                            </p>
                            <div class="flex min-w-0 items-stretch gap-2">
                                <code
                                    dir="ltr"
                                    class="flex h-8 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-input bg-background px-2.5 font-mono text-xs text-foreground"
                                >
                                    <span class="block min-w-0 truncate">{{
                                        connectorName
                                    }}</span>
                                </code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    :aria-label="`${$t('common.actions.copy')} ${$t('mcp.name_label')}`"
                                    @click="copy(connectorName)"
                                >
                                    <IconCopy class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ $t('mcp.url_label') }}
                            </p>
                            <div class="flex min-w-0 items-stretch gap-2">
                                <code
                                    dir="ltr"
                                    class="flex h-8 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-input bg-background px-2.5 font-mono text-xs text-foreground"
                                >
                                    <span class="block min-w-0 truncate">{{
                                        mcpUrl
                                    }}</span>
                                </code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    :aria-label="`${$t('common.actions.copy')} ${$t('mcp.url_label')}`"
                                    @click="copy(mcpUrl)"
                                >
                                    <IconCopy class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="grid gap-1.5">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ $t('mcp.config_label') }}
                            </p>
                            <div class="relative">
                                <pre
                                    dir="ltr"
                                    class="overflow-x-auto rounded-md border border-input bg-background px-3 py-2.5 pe-12 text-left font-mono text-xs leading-5 text-foreground"
                                    :data-testid="`mcp-config-${client.key}`"
                                ><code>{{ configSnippet(client) }}</code></pre>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon-sm"
                                    class="absolute inset-e-2 top-2"
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
