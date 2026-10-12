<script setup lang="ts">
import { IconCopy } from '@tabler/icons-vue';

import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
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
    <div class="flex flex-col gap-10">
        <SettingsSection :title="$t('mcp.copy_step')">
            <div class="flex min-w-0 items-center gap-2">
                <code
                    dir="ltr"
                    class="flex h-8 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-input bg-card px-2 font-mono text-sm text-foreground dark:bg-input/30"
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
        </SettingsSection>

        <SettingsSection :title="$t('mcp.open_step')">
            <ul class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="client in mcpClients"
                    :key="client.id"
                    class="flex-wrap sm:flex-nowrap"
                    :data-testid="`mcp-primary-client-${client.id}`"
                >
                    <template #media>
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            <img
                                :src="client.logo"
                                :alt="client.label"
                                :class="['size-5 object-contain', client.logoClass]"
                            />
                        </span>
                    </template>
                    <p
                        class="truncate text-sm leading-tight font-emphasis text-foreground"
                    >
                        {{ client.label }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ $t(`mcp.clients.${client.id}`) }}
                    </p>
                    <template #actions>
                        <Button
                            as-child
                            variant="outline"
                            class="w-full shrink-0 sm:w-auto"
                        >
                            <a
                                :href="client.settingsUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                :data-testid="`mcp-client-${client.id}`"
                            >
                                {{
                                    $t('mcp.connect', { client: client.label })
                                }}
                            </a>
                        </Button>
                    </template>
                </SettingsListRow>
            </ul>
        </SettingsSection>
    </div>
</template>
