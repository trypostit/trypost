<script setup lang="ts">
import { useHttp, usePage, usePoll } from '@inertiajs/vue3';
import { IconX } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import McpConnectDialog from '@/components/mcp/McpConnectDialog.vue';
import { Button } from '@/components/ui/button';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import {
    mcpAppLogos,
    mcpClients,
    type McpAppLogo,
    type McpClient,
    type McpClientId,
} from '@/lib/mcpClients';
import { store as storePromptAnswer } from '@/routes/app/mcp/prompt';
import type { SharedData } from '@/types';
import { fireCelebration } from '@/utils/confetti';

type PromptAnswer = 'connect' | 'dismiss';

/**
 * The apps people connect most, best known first, as a row of overlapping
 * avatars on top of the card: each one sits over the next.
 */
const AVATAR_APPS = ['chatgpt', 'claude', 'grok', 'gemini', 'cursor', 'n8n'];

const avatars: McpAppLogo[] = AVATAR_APPS.flatMap((id) =>
    mcpAppLogos.filter((app) => app.id === id),
);

const page = usePage<SharedData>();
const isPhone = useBelowBreakpoint('sm');

const storageKey = (): string => `mcp.promptDismissed.${page.props.auth.user.id}`;

const readDismissed = (): boolean => {
    try {
        return window.localStorage.getItem(storageKey()) === 'true';
    } catch {
        return false;
    }
};

const storeDismissed = (): void => {
    try {
        window.localStorage.setItem(storageKey(), 'true');
    } catch {
        return;
    }
};

const dismissed = ref(readDismissed());
const prompt = computed(() => page.props.mcpPrompt ?? null);

/**
 * Invites the user to connect an AI until they connect one or say not now.
 * Connecting is a desktop task, and the "How did you find us?" survey goes first.
 */
const visible = computed(
    (): boolean =>
        prompt.value !== null &&
        !dismissed.value &&
        !isPhone.value &&
        (page.props.referralSources ?? []).length === 0 &&
        page.component !== 'settings/workspace/Mcp',
);

const answerHttp = useHttp<{ answer: PromptAnswer; client: McpClientId | null }>({
    answer: 'dismiss',
    client: null,
});

const track = (answer: PromptAnswer, client: McpClientId | null = null): void => {
    answerHttp.answer = answer;
    answerHttp.client = client;
    answerHttp.post(storePromptAnswer.url()).catch(() => {});
};

const dismiss = (): void => {
    dismissed.value = true;
    storeDismissed();
    track('dismiss');
};

const dialogOpen = ref(false);
const activeClient = ref<McpClient>(mcpClients[0]);
const mcpUrl = ref('');

const connect = (client: McpClient): void => {
    track('connect', client.id);
    activeClient.value = client;
    mcpUrl.value = prompt.value?.mcp_url ?? '';
    dialogOpen.value = true;
};

/** While the steps are open, ask the server whether the AI connected. */
const poll = usePoll(2000, { only: ['mcpPrompt'] }, { autoStart: false });

let stopConfetti = (): void => {};

const followDialog = (open: boolean): void => {
    if (open) {
        poll.start();

        return;
    }

    poll.stop();
};

/** The connection landed: close the steps and celebrate, as the MCP settings do. */
const celebrateConnection = (shown: unknown, before: unknown): void => {
    if (before !== null && shown === null && dialogOpen.value) {
        dialogOpen.value = false;
        stopConfetti();
        stopConfetti = fireCelebration();
    }
};

watch(dialogOpen, followDialog);
watch(prompt, celebrateConnection);

onBeforeUnmount(() => {
    poll.stop();
    stopConfetti();
});
</script>

<template>
    <div class="contents">
        <Transition
            enter-active-class="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2 motion-safe:duration-300"
        >
            <section
                v-if="visible"
                class="fixed end-4 bottom-4 z-40 w-[calc(100vw-2rem)] max-w-sm rounded-xl bg-popover p-4 text-popover-foreground shadow-lg dark:border dark:border-border"
                role="dialog"
                aria-labelledby="mcp-prompt-title"
                aria-describedby="mcp-prompt-body"
                data-testid="mcp-prompt"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex -space-x-2" aria-hidden="true">
                        <span
                            v-for="(app, position) in avatars"
                            :key="app.id"
                            class="relative inline-flex size-9 items-center justify-center rounded-lg bg-muted ring-2 ring-popover"
                            :style="{ zIndex: avatars.length - position }"
                            :data-testid="`mcp-prompt-avatar-${app.id}`"
                        >
                            <component
                                :is="app.logo"
                                :class="['size-5 text-foreground', app.logoClass]"
                            />
                        </span>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="-me-1.5 -mt-1.5 size-7 text-muted-foreground"
                        :aria-label="$t('mcp.prompt.not_now')"
                        data-testid="mcp-prompt-close"
                        @click="dismiss"
                    >
                        <IconX class="size-4" />
                    </Button>
                </div>

                <h2
                    id="mcp-prompt-title"
                    class="mt-3 text-sm font-medium text-foreground"
                >
                    {{ $t('mcp.prompt.title') }}
                </h2>
                <p
                    id="mcp-prompt-body"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ $t('mcp.prompt.body') }}
                </p>

                <div class="mt-4 grid grid-cols-2 gap-2">
                    <Button
                        v-for="client in mcpClients"
                        :key="client.id"
                        type="button"
                        :class="client.brandButtonClass"
                        :data-testid="`mcp-prompt-connect-${client.id}`"
                        @click="connect(client)"
                    >
                        <component :is="client.logo" class="size-4 fill-current" />
                        {{ client.label }}
                    </Button>
                </div>
            </section>
        </Transition>

        <McpConnectDialog
            v-if="mcpUrl"
            v-model:open="dialogOpen"
            :client="activeClient"
            :mcp-url="mcpUrl"
        />
    </div>
</template>
