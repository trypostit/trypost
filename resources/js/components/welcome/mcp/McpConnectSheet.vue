<script setup lang="ts">
import {
    IconArrowUpRight,
    IconCheck,
    IconCopy,
} from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
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

/** The step where the URL is pasted, which carries the URL and the example form. */
const URL_STEP: Record<string, string> = {
    claude: 'paste',
    chatgpt: 'create',
};

const steps = computed((): string[] => STEPS[props.client.id] ?? []);

const asBottomSheet = useBelowBreakpoint('sm');

const copied = ref(false);
let copiedTimeout: ReturnType<typeof setTimeout> | null = null;

const copyUrl = async (): Promise<void> => {
    const didCopy = await copyToClipboard(props.mcpUrl, undefined, {
        showSuccessToast: false,
    });

    if (!didCopy) {
        return;
    }

    copied.value = true;

    if (copiedTimeout) {
        clearTimeout(copiedTimeout);
    }

    copiedTimeout = setTimeout(() => {
        copied.value = false;
    }, 2000);
};

onBeforeUnmount(() => {
    if (copiedTimeout) {
        clearTimeout(copiedTimeout);
    }
});
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            :side="asBottomSheet ? 'bottom' : 'right'"
            class="gap-0 max-sm:max-h-[92svh] max-sm:rounded-t-2xl sm:w-full sm:max-w-xl"
            data-testid="welcome-mcp-sheet"
        >
            <SheetHeader class="flex-row items-center gap-4 p-6 pe-14">
                <span
                    class="inline-flex size-12 shrink-0 items-center justify-center rounded-xl border border-border bg-card"
                    aria-hidden="true"
                >
                    <img
                        :src="client.logo"
                        alt=""
                        :class="['size-6', client.logoClass]"
                    />
                </span>
                <div class="flex min-w-0 flex-col gap-1">
                    <SheetTitle class="font-heading text-xl font-medium tracking-tight">
                        {{ $t('welcome.mcp.sheet_title', { client: client.label }) }}
                    </SheetTitle>
                    <SheetDescription class="text-sm text-muted-foreground">
                        {{ $t('welcome.mcp.sheet_description') }}
                    </SheetDescription>
                </div>
            </SheetHeader>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 pt-2 pb-8">
                <ol class="flex flex-col">
                    <li
                        v-for="(step, index) in steps"
                        :key="step"
                        class="flex gap-4"
                        :data-testid="`welcome-mcp-step-${step}`"
                    >
                        <div class="flex flex-col items-center" aria-hidden="true">
                            <span
                                class="inline-flex size-8 shrink-0 items-center justify-center rounded-full border border-border bg-card text-sm font-semibold text-foreground tabular-nums"
                            >
                                {{ index + 1 }}
                            </span>
                            <span
                                v-if="index < steps.length - 1"
                                class="my-1.5 w-px flex-1 bg-border"
                            />
                        </div>
                        <div
                            :class="[
                                'flex min-w-0 flex-1 flex-col gap-4 pt-1.5',
                                index < steps.length - 1 ? 'pb-7' : '',
                            ]"
                        >
                            <p class="text-sm font-medium text-foreground">
                                {{ $t(`welcome.mcp.steps.${client.id}.${step}`) }}
                            </p>

                            <template v-if="step === URL_STEP[client.id]">
                                <div class="flex min-w-0 items-center gap-2">
                                    <code
                                        dir="ltr"
                                        class="flex h-9 min-w-0 flex-1 items-center overflow-hidden rounded-lg border border-input bg-card px-3 font-mono text-sm text-foreground dark:bg-input/30"
                                        data-testid="welcome-mcp-url"
                                    >
                                        <span class="block min-w-0 truncate">{{ mcpUrl }}</span>
                                    </code>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        class="h-9 shrink-0"
                                        data-testid="welcome-mcp-copy-url"
                                        @click="copyUrl"
                                    >
                                        <IconCheck
                                            v-if="copied"
                                            class="size-4 text-green-500"
                                        />
                                        <IconCopy v-else class="size-4" />
                                        {{ copied ? $t('mcp.copied') : $t('mcp.copy') }}
                                    </Button>
                                </div>

                                <div
                                    class="rounded-xl bg-accent p-4"
                                    aria-hidden="true"
                                >
                                    <div
                                        class="overflow-hidden rounded-xl border border-border-strong bg-card"
                                    >
                                        <div
                                            class="relative flex h-8 items-center gap-1.5 border-b border-border bg-muted px-3"
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
                                                {{ client.label }}
                                            </span>
                                        </div>
                                        <div class="flex flex-col gap-3 p-4">
                                            <div class="flex flex-col gap-1.5">
                                                <span class="text-xs font-medium text-muted-foreground">
                                                    {{ $t('mcp.name_label') }}
                                                </span>
                                                <span
                                                    class="flex h-8 items-center rounded-md border border-input bg-card px-2.5 text-sm text-foreground"
                                                >
                                                    {{ $t('mcp.connector_name') }}
                                                </span>
                                            </div>
                                            <div class="flex flex-col gap-1.5">
                                                <span class="text-xs font-medium text-muted-foreground">
                                                    {{ $t('mcp.url_label') }}
                                                </span>
                                                <span
                                                    dir="ltr"
                                                    class="flex h-8 min-w-0 items-center rounded-md border border-primary-strong bg-card px-2.5 font-mono text-xs text-foreground ring-3 ring-primary-strong/15"
                                                >
                                                    <span class="truncate">{{ mcpUrl }}</span>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </li>
                </ol>
            </div>

            <div
                class="flex flex-col-reverse gap-3 border-t border-border bg-card px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
                aria-live="polite"
            >
                <p
                    class="flex items-center justify-center gap-1.5 text-sm text-muted-foreground"
                    data-testid="welcome-mcp-sheet-waiting"
                >
                    <Spinner class="size-3.5 shrink-0 text-primary-text" />
                    {{ $t('welcome.mcp.waiting') }}
                </p>
                <Button
                    as="a"
                    :href="client.settingsUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    size="lg"
                    data-testid="welcome-mcp-open"
                >
                    {{ $t('welcome.mcp.open', { client: client.label }) }}
                    <IconArrowUpRight class="size-4 rtl:-scale-x-100" />
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
