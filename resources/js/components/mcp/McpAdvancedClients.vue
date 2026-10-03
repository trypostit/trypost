<script setup lang="ts">
import { IconChevronDown, IconCopy } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import SettingsSection from '@/components/settings/SettingsSection.vue';
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
    logoClass?: string;
    httpType: boolean;
    configRoot: McpConfigRoot;
}

const advancedClients: AdvancedMcpClient[] = [
    {
        key: 'cursor',
        name: 'mcp.clients.cursor_name',
        description: 'mcp.clients.cursor',
        logo: '/images/ai/cursor.svg',
        logoClass: 'dark:invert',
        httpType: false,
        configRoot: 'mcpServers',
    },
    {
        key: 'vscode',
        name: 'mcp.clients.vscode_name',
        description: 'mcp.clients.vscode',
        logo: '/images/ai/vscode.svg',
        httpType: true,
        configRoot: 'servers',
    },
    {
        key: 'claude_code',
        name: 'mcp.clients.claude_code_name',
        description: 'mcp.clients.claude_code',
        logo: '/images/ai/claude.svg',
        httpType: true,
        configRoot: 'mcpServers',
    },
    {
        key: 'other',
        name: 'mcp.clients.other_name',
        description: 'mcp.clients.other',
        logo: '/images/ai/other-clients.svg',
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
    <SettingsSection
        :title="$t('mcp.other_clients_title')"
        :description="$t('mcp.other_clients_description')"
    >
        <div class="flex flex-col gap-2">
            <Collapsible
                v-for="client in advancedClients"
                :key="client.key"
                class="overflow-hidden rounded-xl border border-border bg-card"
                :open="openClient === client.key"
                @update:open="(open) => setClientOpen(client.key, open)"
            >
                <CollapsibleTrigger
                    class="flex w-full cursor-pointer items-center gap-3 p-4 text-start transition-control outline-none hover:bg-muted focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                    :data-testid="`mcp-advanced-client-${client.key}`"
                >
                    <span
                        class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                    >
                        <img
                            :src="client.logo"
                            :alt="$t(client.name)"
                            :class="['size-5 object-contain', client.logoClass]"
                        />
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col gap-1">
                        <span
                            class="truncate text-sm leading-tight font-emphasis text-foreground"
                        >
                            {{ $t(client.name) }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{ $t(client.description) }}
                        </span>
                    </span>
                    <IconChevronDown
                        class="pointer-events-none size-4 shrink-0 text-muted-foreground transition-transform duration-200 motion-reduce:transition-none"
                        :class="openClient === client.key ? 'rotate-180' : ''"
                    />
                </CollapsibleTrigger>

                <CollapsibleContent
                    class="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down motion-reduce:data-[state=closed]:animate-none motion-reduce:data-[state=open]:animate-none"
                >
                    <div
                        class="flex flex-col gap-6 border-t border-border bg-muted/40 p-4"
                    >
                        <p class="text-sm text-muted-foreground">
                            {{ $t('mcp.step_add') }}
                        </p>

                        <div class="flex flex-col gap-2">
                            <p class="text-sm font-medium text-foreground">
                                {{ $t('mcp.name_label') }}
                            </p>
                            <div class="flex min-w-0 items-center gap-2">
                                <code
                                    dir="ltr"
                                    class="flex h-8 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-input bg-card px-2 font-mono text-sm text-foreground dark:bg-input/30"
                                >
                                    <span class="block min-w-0 truncate">{{
                                        connectorName
                                    }}</span>
                                </code>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="shrink-0 text-muted-foreground"
                                    :aria-label="`${$t('common.actions.copy')} ${$t('mcp.name_label')}`"
                                    @click="copy(connectorName)"
                                >
                                    <IconCopy class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <p class="text-sm font-medium text-foreground">
                                {{ $t('mcp.url_label') }}
                            </p>
                            <div class="flex min-w-0 items-center gap-2">
                                <code
                                    dir="ltr"
                                    class="flex h-8 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-input bg-card px-2 font-mono text-sm text-foreground dark:bg-input/30"
                                >
                                    <span class="block min-w-0 truncate">{{
                                        mcpUrl
                                    }}</span>
                                </code>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="shrink-0 text-muted-foreground"
                                    :aria-label="`${$t('common.actions.copy')} ${$t('mcp.url_label')}`"
                                    @click="copy(mcpUrl)"
                                >
                                    <IconCopy class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <p class="text-sm font-medium text-foreground">
                                {{ $t('mcp.config_label') }}
                            </p>
                            <div class="relative">
                                <pre
                                    dir="ltr"
                                    class="overflow-x-auto rounded-md border border-input bg-card px-3 py-2.5 pe-12 text-left font-mono text-xs leading-5 text-foreground dark:bg-input/30"
                                    :data-testid="`mcp-config-${client.key}`"
                                ><code>{{ configSnippet(client) }}</code></pre>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="absolute inset-e-1.5 top-1.5 text-muted-foreground"
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
    </SettingsSection>
</template>
