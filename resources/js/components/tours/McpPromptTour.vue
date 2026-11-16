<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import { IconX } from '@tabler/icons-vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import McpConnectDialog from '@/components/mcp/McpConnectDialog.vue';
import { Button } from '@/components/ui/button';
import { useTourOnScreen, useTourProgress } from '@/composables/tours/useTour';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { filled } from '@/lib/helpers';
import { claudeMcpClient, mcpAppLogos, mcpClients } from '@/lib/mcpClients';
import {
    show as showConnectionStatus,
    store as storePromptAnswer,
} from '@/routes/app/mcp/prompt';
import type { SharedData } from '@/types';
import type { McpAppLogo, McpClient, McpClientId } from '@/types/mcp';
import { fireCelebration } from '@/utils/confetti';

interface McpConnectionStatus {
    connected: boolean;
}

/**
 * The apps people connect most, best known first, as a row of overlapping
 * avatars on top of the card: each one sits over the next.
 */
const AVATAR_APPS = ['chatgpt', 'claude', 'grok', 'gemini', 'cursor', 'n8n'];

const avatars: McpAppLogo[] = AVATAR_APPS.flatMap((id) =>
    mcpAppLogos.filter((app) => app.id === id),
);

/** Each avatar sits over the next one: the first is on top. */
const avatarStyle = (position: number): { zIndex: number } => ({
    zIndex: avatars.length - position,
});

const page = usePage<SharedData>();
const isPhone = useBelowBreakpoint('sm');

const { wentThrough: dismissed, record } = useTourProgress('mcp.prompt');
const tourOnScreen = useTourOnScreen();
const connected = ref(false);
const prompt = computed(() => page.props.mcpPrompt ?? null);

/**
 * Invites the user to connect an AI until they connect one or say not now.
 * Connecting is a desktop task, and the "How did you find us?" survey and an
 * open tour go first.
 */
const visible = computed((): boolean => {
    if (!filled(prompt.value)) {
        return false;
    }

    if (dismissed.value) {
        return false;
    }

    if (connected.value) {
        return false;
    }

    if (isPhone.value) {
        return false;
    }

    if (filled(page.props.referralSources)) {
        return false;
    }

    if (tourOnScreen.value) {
        return false;
    }

    return page.component !== 'settings/workspace/Mcp';
});

/** How long the card waits before it shows, in milliseconds. */
const SHOW_DELAY_MS = 400;

/**
 * Whether the card is on screen. It shows a moment after it may, and hides at
 * once: a tour opening on the same page load (the visit after answering the
 * referral survey remounts the layout) goes first, instead of the card
 * flashing in under it and its transition being cut off halfway.
 */
const shown = ref(false);
let showTimer: ReturnType<typeof setTimeout> | undefined;

const followVisible = (canShow: boolean): void => {
    clearTimeout(showTimer);

    if (!canShow) {
        shown.value = false;

        return;
    }

    showTimer = setTimeout(() => {
        shown.value = true;
    }, SHOW_DELAY_MS);
};

watch(visible, followVisible, { immediate: true });

const connectHttp = useHttp<{ answer: 'connect'; client: McpClientId }>({
    answer: 'connect',
    client: 'claude',
});
const dismissHttp = useHttp<{ answer: 'dismiss' }>({ answer: 'dismiss' });
const statusHttp = useHttp<Record<string, never>, McpConnectionStatus>({});

const dismiss = (): void => {
    record('skipped', 1);
    dismissHttp.post(storePromptAnswer.url()).catch(() => {});
};

const dialogOpen = ref(false);
const activeClient = ref<McpClient>(claudeMcpClient);
const mcpUrl = ref('');

const connect = (client: McpClient): void => {
    connectHttp.client = client.id;
    connectHttp.post(storePromptAnswer.url()).catch(() => {});
    activeClient.value = client;
    mcpUrl.value = prompt.value?.mcp_url ?? '';
    dialogOpen.value = true;
};

let stopConfetti = (): void => {};

/** An AI connected: hide the card, close the steps and celebrate, as the MCP settings do. */
const celebrateConnection = (): void => {
    connected.value = true;
    dialogOpen.value = false;
    stopConfetti();
    stopConfetti = fireCelebration();
};

const checkConnection = async (): Promise<void> => {
    if (statusHttp.processing) {
        return;
    }

    const status = await statusHttp
        .get(showConnectionStatus.url())
        .catch(() => null);

    if (status?.connected === true) {
        celebrateConnection();
    }
};

/**
 * While the card or its steps are on screen, ask whether an AI connected to
 * this workspace, from the card or from anywhere else.
 */
const poll = useIntervalFn(checkConnection, 2000, { immediate: false });

const followCard = (onScreen: boolean): void => {
    if (onScreen) {
        poll.resume();

        return;
    }

    poll.pause();
};

/** The card or its steps are on screen. */
const isOnScreen = computed((): boolean => {
    if (shown.value) {
        return true;
    }

    return dialogOpen.value;
});

watch(isOnScreen, followCard, { immediate: true });

onBeforeUnmount(() => {
    clearTimeout(showTimer);
    poll.pause();
    stopConfetti();
});
</script>

<template>
    <div class="contents">
        <Transition
            enter-active-class="motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2 motion-safe:duration-300"
        >
            <aside
                v-if="shown"
                class="fixed end-4 bottom-4 z-40 w-[calc(100vw-2rem)] max-w-sm rounded-xl bg-popover p-4 text-popover-foreground shadow-lg dark:border dark:border-border"
                aria-labelledby="mcp-prompt-title"
                aria-describedby="mcp-prompt-body"
                data-testid="mcp-prompt"
            >
                <div class="flex items-start justify-between gap-3">
                    <div
                        class="flex -space-x-2"
                        aria-hidden="true"
                        data-testid="mcp-prompt-avatars"
                    >
                        <span
                            v-for="(app, position) in avatars"
                            :key="app.id"
                            class="relative inline-flex size-9 items-center justify-center rounded-lg bg-muted ring-2 ring-popover"
                            :style="avatarStyle(position)"
                            :data-testid="`mcp-prompt-avatar-${app.id}`"
                        >
                            <component
                                :is="app.logo"
                                :class="[
                                    'size-5 text-foreground',
                                    app.logoClass,
                                ]"
                            />
                        </span>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="-me-1.5 -mt-1.5 size-7 text-muted-foreground"
                        :aria-label="$t('tours.mcp.prompt.steps.card.not_now')"
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
                    {{ $t('tours.mcp.prompt.steps.card.title') }}
                </h2>
                <p
                    id="mcp-prompt-body"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    {{ $t('tours.mcp.prompt.steps.card.description') }}
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
                        <component
                            :is="client.logo"
                            class="size-4 fill-current"
                        />
                        {{ client.label }}
                    </Button>
                </div>
            </aside>
        </Transition>

        <McpConnectDialog
            v-if="mcpUrl"
            v-model:open="dialogOpen"
            :client="activeClient"
            :mcp-url="mcpUrl"
        />
    </div>
</template>
