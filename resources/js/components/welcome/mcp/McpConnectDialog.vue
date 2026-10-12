<script setup lang="ts">
import {
    IconArrowUpRight,
    IconCheck,
    IconCopy,
} from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

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
import { copyToClipboard } from '@/lib/utils';

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
};

const steps = computed((): string[] => STEPS[props.client.id] ?? []);

const focusDialogFrame = (event: Event): void => {
    event.preventDefault();
    (event.target as HTMLElement | null)?.focus({ preventScroll: true });
};

type CopiedField = 'name' | 'url';

const copiedField = ref<CopiedField | null>(null);
let copiedTimeout: ReturnType<typeof setTimeout> | null = null;

const copyField = async (field: CopiedField, value: string): Promise<void> => {
    const didCopy = await copyToClipboard(value, undefined, {
        showSuccessToast: false,
    });

    if (!didCopy) {
        return;
    }

    copiedField.value = field;

    if (copiedTimeout) {
        clearTimeout(copiedTimeout);
    }

    copiedTimeout = setTimeout(() => {
        copiedField.value = null;
    }, 2000);
};

const copyName = (name: string): Promise<void> => copyField('name', name);

const copyUrl = (): Promise<void> => copyField('url', props.mcpUrl);

onBeforeUnmount(() => {
    if (copiedTimeout) {
        clearTimeout(copiedTimeout);
    }
});
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
                    <component
                        :is="client.logo"
                        :class="['size-6', client.brandClass]"
                    />
                </span>
                <div class="flex min-w-0 flex-col gap-1">
                    <DialogTitle>
                        {{ $t('welcome.mcp.dialog_title', { client: client.label }) }}
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
                        <component
                            :is="client.logo"
                            :class="['size-3.5', client.brandClass]"
                        />
                        {{ client.label }}
                    </span>
                </div>
                <div class="flex flex-col gap-3 p-4">
                    <div class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium text-muted-foreground">
                            {{ $t('mcp.name_label') }}
                        </span>
                        <div
                            class="flex h-9 min-w-0 items-center gap-2 rounded-md border border-input bg-card ps-3 pe-1"
                        >
                            <span
                                class="min-w-0 flex-1 truncate text-sm text-foreground"
                                data-testid="welcome-mcp-name"
                            >{{ $t('mcp.connector_name') }}</span>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                class="h-7 shrink-0"
                                data-testid="welcome-mcp-copy-name"
                                @click="copyName($t('mcp.connector_name'))"
                            >
                                <IconCheck
                                    v-if="copiedField === 'name'"
                                    class="size-3.5 text-green-500"
                                />
                                <IconCopy v-else class="size-3.5" />
                                {{ copiedField === 'name' ? $t('mcp.copied') : $t('welcome.mcp.copy') }}
                            </Button>
                        </div>
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <span class="text-xs font-medium text-muted-foreground">
                            {{ $t('mcp.url_label') }}
                        </span>
                        <div
                            class="flex h-9 min-w-0 items-center gap-2 rounded-md border border-input bg-card ps-3 pe-1"
                        >
                            <span
                                dir="ltr"
                                class="min-w-0 flex-1 truncate text-sm text-foreground"
                                data-testid="welcome-mcp-url"
                            >{{ mcpUrl }}</span>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                class="h-7 shrink-0"
                                data-testid="welcome-mcp-copy-url"
                                @click="copyUrl"
                            >
                                <IconCheck
                                    v-if="copiedField === 'url'"
                                    class="size-3.5 text-green-500"
                                />
                                <IconCopy v-else class="size-3.5" />
                                {{ copiedField === 'url' ? $t('mcp.copied') : $t('welcome.mcp.copy') }}
                            </Button>
                        </div>
                    </div>
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
