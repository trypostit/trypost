<script setup lang="ts">
import { IconCopy } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { mcpClients } from '@/lib/mcpClients';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{
    mcpUrl: string;
    copiedMessage: string;
}>();

const copyMcpUrl = (): void => {
    copyToClipboard(props.mcpUrl, props.copiedMessage);
};
</script>

<template>
    <div class="space-y-6">
        <div class="space-y-2">
            <p class="text-sm font-medium text-foreground">
                {{ $t('mcp.copy_step') }}
            </p>
            <div class="flex min-w-0 items-stretch gap-2">
                <code
                    dir="ltr"
                    class="flex h-9 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-input bg-muted px-3 font-mono text-sm text-foreground"
                >
                    <span class="block min-w-0 truncate">{{ mcpUrl }}</span>
                </code>
                <Button
                    type="button"
                    variant="outline"
                    class="shrink-0"
                    data-testid="copy-mcp-url"
                    @click="copyMcpUrl"
                >
                    <IconCopy class="size-4" />
                    {{ $t('mcp.copy') }}
                </Button>
            </div>
        </div>

        <div class="space-y-2">
            <p class="text-sm font-medium text-foreground">
                {{ $t('mcp.open_step') }}
            </p>

            <div class="grid gap-3 md:grid-cols-2">
                <article
                    v-for="client in mcpClients"
                    :key="client.id"
                    class="flex flex-col gap-4 rounded-md border border-border bg-card p-4"
                >
                    <div class="flex items-start gap-3">
                        <span
                            :class="[
                                client.theme.bg,
                                'inline-flex size-10 shrink-0 items-center justify-center rounded-md border border-border',
                            ]"
                        >
                            <img
                                :src="client.logo"
                                :alt="client.label"
                                class="size-6 object-contain"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-medium text-foreground">
                                {{ client.label }}
                            </h3>
                            <p
                                class="mt-0.5 text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ $t(`mcp.clients.${client.id}`) }}
                            </p>
                        </div>
                    </div>

                    <Button as-child variant="outline" class="w-full">
                        <a
                            :href="client.settingsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            :data-testid="`mcp-client-${client.id}`"
                        >
                            {{ $t('mcp.connect', { client: client.label }) }}
                        </a>
                    </Button>
                </article>
            </div>
        </div>
    </div>
</template>
