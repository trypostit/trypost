<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import { IconX } from '@tabler/icons-vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import McpConnectDialog from '@/components/mcp/McpConnectDialog.vue';
import { Button } from '@/components/ui/button';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { useUserPref } from '@/composables/useUserPref';
import {
    mcpAppLogos,
    mcpClients,
    type McpAppLogo,
    type McpClient,
    type McpClientId,
} from '@/lib/mcpClients';
import {
    show as showConnectionStatus,
    store as storePromptAnswer,
} from '@/routes/app/mcp/prompt';
import type { SharedData } from '@/types';
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

const page = usePage<SharedData>();
const isPhone = useBelowBreakpoint('sm');

const dismissed = useUserPref('mcp.promptDismissed', false);
const connected = ref(false);
const prompt = computed(() => page.props.mcpPrompt ?? null);

/**
 * Invites the user to connect an AI until they connect one or say not now.
 * Connecting is a desktop task, and the "How did you find us?" survey goes first.
 */
const visible = computed(
    (): boolean =>
        prompt.value !== null &&
        !dismissed.value &&
        !connected.value &&
        !isPhone.value &&
        (page.props.referralSources ?? []).length === 0 &&
        page.component !== 'settings/workspace/Mcp',
);

const connectHttp = useHttp<{ answer: 'connect'; client: McpClientId }>({
    answer: 'connect',
    client: 'claude',
});
const dismissHttp = useHttp<{ answer: 'dismiss' }>({ answer: 'dismiss' });
const statusHttp = useHttp<Record<string, never>, McpConnectionStatus>({});

const dismiss = (): void => {
    dismissed.value = true;
    dismissHttp.post(storePromptAnswer.url()).catch(() => {});
};

const dialogOpen = ref(false);
const activeClient = ref<McpClient>(mcpClients[0]);
const mcpUrl = ref('');

const connect = (client: McpClient): void => {
    connectHttp.client = client.id;
    connectHttp.post(storePromptAnswer.url()).catch(() => {});
    activeClient.value = client;
    mcpUrl.value = prompt.value?.mcp_url ?? '';
    dialogOpen.value = true;
};

let stopConfetti = (): void => {};

/** The AI connected: close the steps and celebrate, as the MCP settings do. */
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

    if (status?.connected === true && dialogOpen.value) {
        celebrateConnection();
    }
};

/** While the steps are open, ask whether the AI connected to this workspace. */
const poll = useIntervalFn(checkConnection, 2000, { immediate: false });

const followDialog = (open: boolean): void => {
    if (open) {
        poll.resume();

        return;
    }

    poll.pause();
};

watch(dialogOpen, followDialog);

onBeforeUnmount(() => {
    poll.pause();
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
