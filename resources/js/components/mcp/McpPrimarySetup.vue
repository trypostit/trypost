<script setup lang="ts">
import { IconCopy, IconLink } from '@tabler/icons-vue';

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
        <div>
            <p class="mb-3 text-sm font-bold">
                {{ $t('mcp.copy_step') }}
            </p>
            <div
                class="flex flex-col gap-2 rounded-xl border-2 border-foreground bg-background p-2 shadow-2xs sm:flex-row sm:items-center"
            >
                <div class="flex min-w-0 flex-1 items-center gap-2 px-2">
                    <IconLink class="size-4 shrink-0 text-muted-foreground" />
                    <code
                        dir="ltr"
                        class="min-w-0 flex-1 truncate text-left text-sm"
                    >
                        {{ mcpUrl }}
                    </code>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="shrink-0"
                    data-testid="copy-mcp-url"
                    @click="copyMcpUrl"
                >
                    <IconCopy class="size-4" />
                    {{ $t('mcp.copy') }}
                </Button>
            </div>
        </div>

        <div>
            <p class="mb-3 text-sm font-bold">
                {{ $t('mcp.open_step') }}
            </p>

            <div class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="client in mcpClients"
                    :key="client.id"
                    class="flex flex-col gap-4 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs"
                >
                    <div class="flex items-start gap-4">
                        <span
                            :class="[
                                client.theme.bg,
                                client.theme.rotate,
                                'inline-flex size-12 shrink-0 items-center justify-center rounded-xl border-2 border-foreground shadow-sm',
                            ]"
                        >
                            <img
                                :src="client.logo"
                                :alt="client.label"
                                class="size-7 object-contain"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-bold">
                                {{ client.label }}
                            </h3>
                            <p
                                class="mt-1 text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ $t(`mcp.clients.${client.id}`) }}
                            </p>
                        </div>
                    </div>

                    <Button as-child class="w-full">
                        <a
                            :href="client.settingsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            :data-testid="`mcp-client-${client.id}`"
                        >
                            {{
                                $t('mcp.connect', {
                                    client: client.label,
                                })
                            }}
                        </a>
                    </Button>
                </article>
            </div>
        </div>
    </div>
</template>
