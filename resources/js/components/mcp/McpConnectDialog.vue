<script setup lang="ts">
import { IconArrowUpRight } from '@tabler/icons-vue';
import { computed } from 'vue';

import McpCopyField from '@/components/mcp/McpCopyField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { McpClient } from '@/lib/mcpClients';

const props = defineProps<{
    client: McpClient;
    mcpUrl: string;
}>();

const open = defineModel<boolean>('open', { default: false });

/**
 * What to do in each client once its connector page is open. ChatGPT needs
 * developer mode before it accepts a custom MCP app.
 */
const STEPS: Record<string, string[]> = {
    claude: ['paste', 'add', 'login'],
    chatgpt: ['developer', 'create', 'login'],
    other: ['add', 'paste', 'login'],
};

const steps = computed((): string[] => STEPS[props.client.id] ?? []);

const configSnippet = (connectorName: string): string =>
    JSON.stringify(
        { mcpServers: { [connectorName]: { url: props.mcpUrl } } },
        null,
        2,
    );

const focusDialogFrame = (event: Event): void => {
    event.preventDefault();
    (event.target as HTMLElement | null)?.focus({ preventScroll: true });
};

</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="outline-none sm:max-w-xl"
            data-testid="welcome-mcp-dialog"
            @open-auto-focus="focusDialogFrame"
        >
            <DialogHeader class="flex-row items-start gap-4 pe-8 text-start">
                <span
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-xl border border-border bg-card"
                    aria-hidden="true"
                >
                    <img
                        :src="client.logo"
                        alt=""
                        :class="['size-6', client.logoClass]"
                    />
                </span>
                <div class="flex min-w-0 flex-col gap-1">
                    <DialogTitle>
                        {{
                            client.labelKey
                                ? $t('mcp.other_dialog_title')
                                : $t('welcome.mcp.dialog_title', {
                                      client: client.label,
                                  })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{ $t('welcome.mcp.dialog_description') }}
                    </DialogDescription>
                </div>
            </DialogHeader>

            <ol class="flex flex-col gap-2.5">
                <li
                    v-for="(step, index) in steps"
                    :key="step"
                    class="flex items-start gap-2.5 text-sm text-foreground"
                    :data-testid="`welcome-mcp-step-${step}`"
                >
                    <span
                        class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-secondary text-xs font-semibold tabular-nums"
                        aria-hidden="true"
                    >
                        {{ index + 1 }}
                    </span>
                    {{ $t(`welcome.mcp.steps.${client.id}.${step}`) }}
                </li>
            </ol>

            <div
                class="overflow-hidden rounded-xl border border-border-strong bg-card"
            >
                <div
                    class="relative flex h-8 items-center gap-1.5 border-b border-border bg-muted px-3"
                    aria-hidden="true"
                >
                    <span class="size-2.5 rounded-full bg-[#ff5f57]" />
                    <span class="size-2.5 rounded-full bg-[#febc2e]" />
                    <span class="size-2.5 rounded-full bg-[#28c840]" />
                    <span
                        class="absolute inset-x-0 flex items-center justify-center gap-1.5 text-xs font-medium text-muted-foreground"
                    >
                        <img
                            :src="client.logo"
                            alt=""
                            :class="['size-3.5', client.logoClass]"
                        />
                        {{ client.labelKey ? $t(client.label) : client.label }}
                    </span>
                </div>
                <div class="flex flex-col gap-3 p-4">
                    <McpCopyField
                        :label="$t('mcp.name_label')"
                        :value="$t('mcp.connector_name')"
                        testid="welcome-mcp-name"
                    />
                    <McpCopyField
                        :label="$t('mcp.url_label')"
                        :value="mcpUrl"
                        testid="welcome-mcp-url"
                        ltr
                    />
                    <McpCopyField
                        v-if="!client.settingsUrl"
                        :label="$t('mcp.config_label')"
                        :value="configSnippet($t('mcp.connector_name'))"
                        testid="welcome-mcp-config"
                        multiline
                    />
                </div>
            </div>

            <DialogFooter>
                <p
                    class="flex items-center gap-1.5 text-sm text-muted-foreground max-sm:w-full max-sm:justify-center sm:me-auto"
                    aria-live="polite"
                    data-testid="welcome-mcp-dialog-waiting"
                >
                    <Spinner class="size-3.5 shrink-0 text-primary-text" />
                    {{ $t('welcome.mcp.waiting') }}
                </p>
                <Button
                    v-if="client.settingsUrl"
                    as="a"
                    :href="client.settingsUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-testid="welcome-mcp-open"
                >
                    {{ $t('welcome.mcp.open', { client: client.label }) }}
                    <IconArrowUpRight class="size-4 rtl:-scale-x-100" />
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
