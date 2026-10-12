<script setup lang="ts">
import {
    IconArrowUpRight,
    IconCircleCheck,
    IconCircleCheckFilled,
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
    urlCopied: boolean;
    connectedName: string | null;
}>();

const emit = defineEmits<{
    (event: 'continue'): void;
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

/** The step whose form the mini mockup illustrates. */
const FORM_STEP: Record<string, string> = {
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

const continueToSchedule = (): void => {
    emit('continue');
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
            class="gap-0 overflow-y-auto max-sm:max-h-[90svh] max-sm:rounded-t-xl sm:max-w-md"
            data-testid="welcome-mcp-sheet"
        >
            <SheetHeader class="border-b border-border p-5 pe-14">
                <SheetTitle class="flex items-center gap-2">
                    <img
                        :src="client.logo"
                        alt=""
                        :class="['size-5', client.logoClass]"
                    />
                    {{ $t('welcome.mcp.sheet_title', { client: client.label }) }}
                </SheetTitle>
                <SheetDescription class="sr-only">
                    {{ $t('welcome.mcp.description') }}
                </SheetDescription>
            </SheetHeader>

            <div class="flex flex-col gap-6 p-5">
                <div class="flex flex-col gap-2">
                    <p
                        v-if="urlCopied"
                        class="flex items-center gap-1.5 text-sm font-medium text-foreground"
                        data-testid="welcome-mcp-url-copied"
                    >
                        <IconCircleCheckFilled
                            class="size-4 shrink-0 text-green-500"
                        />
                        {{ $t('welcome.mcp.url_copied') }}
                    </p>
                    <div class="flex min-w-0 items-center gap-2">
                        <code
                            dir="ltr"
                            class="flex h-8 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-input bg-card px-2 font-mono text-sm text-foreground dark:bg-input/30"
                            data-testid="welcome-mcp-url"
                        >
                            <span class="block min-w-0 truncate">{{
                                mcpUrl
                            }}</span>
                        </code>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            class="shrink-0"
                            :aria-label="$t('mcp.copy')"
                            data-testid="welcome-mcp-copy-url"
                            @click="copyUrl"
                        >
                            <IconCircleCheck
                                v-if="copied"
                                class="size-4 text-green-500"
                            />
                            <IconCopy v-else class="size-4" />
                        </Button>
                    </div>
                </div>

                <ol class="flex flex-col gap-4">
                    <li
                        v-for="(step, index) in steps"
                        :key="step"
                        class="flex items-start gap-3"
                        :data-testid="`welcome-mcp-step-${step}`"
                    >
                        <span
                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary text-xs font-semibold text-foreground tabular-nums"
                            aria-hidden="true"
                        >
                            {{ index + 1 }}
                        </span>
                        <div class="flex min-w-0 flex-1 flex-col gap-3">
                            <span class="pt-0.5 text-sm text-foreground">
                                {{
                                    $t(
                                        `welcome.mcp.steps.${client.id}.${step}`,
                                    )
                                }}
                            </span>
                            <div
                                v-if="step === FORM_STEP[client.id]"
                                class="flex flex-col gap-3 rounded-lg border border-border bg-muted p-3"
                                aria-hidden="true"
                            >
                                <div class="flex flex-col gap-1">
                                    <span
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        {{ $t('mcp.name_label') }}
                                    </span>
                                    <span
                                        class="flex h-8 items-center rounded-md border border-input bg-card px-2 text-sm text-foreground"
                                    >
                                        {{ $t('mcp.connector_name') }}
                                    </span>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <span
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        {{ $t('mcp.url_label') }}
                                    </span>
                                    <span
                                        dir="ltr"
                                        class="flex h-8 min-w-0 items-center rounded-md border-2 border-primary-strong bg-card px-2 font-mono text-xs text-foreground"
                                    >
                                        <span class="truncate">{{
                                            mcpUrl
                                        }}</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </li>
                </ol>

                <div
                    class="flex flex-col gap-3 border-t border-border pt-5"
                    aria-live="polite"
                >
                    <template v-if="connectedName">
                        <p
                            class="flex items-center gap-1.5 text-sm font-medium text-foreground"
                            data-testid="welcome-mcp-sheet-connected"
                        >
                            <IconCircleCheckFilled
                                class="size-4 shrink-0 text-green-500"
                            />
                            {{
                                $t('welcome.mcp.connected', {
                                    client: connectedName,
                                })
                            }}
                        </p>
                        <Button
                            type="button"
                            size="lg"
                            data-testid="welcome-mcp-sheet-continue"
                            @click="continueToSchedule"
                        >
                            {{ $t('welcome.continue') }}
                        </Button>
                    </template>
                    <template v-else>
                        <p
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
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
                            variant="secondary"
                            size="lg"
                            data-testid="welcome-mcp-reopen"
                        >
                            {{
                                $t('welcome.mcp.reopen', {
                                    client: client.label,
                                })
                            }}
                            <IconArrowUpRight
                                class="size-4 text-muted-foreground rtl:-scale-x-100"
                            />
                        </Button>
                    </template>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
