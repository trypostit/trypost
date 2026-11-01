<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import { IconMcp } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { index as mcpIndex } from '@/routes/app/mcp';
import { store as storePromptAnswer } from '@/routes/app/mcp/prompt';
import type { SharedData } from '@/types';

type PromptAnswer = 'connect' | 'dismiss';

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

/**
 * Invites the user to connect an AI until they connect one or say not now.
 * Connecting is a desktop task, and the "How did you find us?" survey goes first.
 */
const visible = computed(
    (): boolean =>
        page.props.mcpPrompt === true &&
        !dismissed.value &&
        !isPhone.value &&
        (page.props.referralSources ?? []).length === 0 &&
        page.component !== 'settings/workspace/Mcp',
);

const answerHttp = useHttp<{ answer: PromptAnswer }>({ answer: 'dismiss' });

const track = (answer: PromptAnswer): void => {
    answerHttp.answer = answer;
    answerHttp.post(storePromptAnswer.url()).catch(() => {});
};

const dismiss = (): void => {
    dismissed.value = true;
    storeDismissed();
    track('dismiss');
};

const connect = (): void => {
    track('connect');
    router.visit(mcpIndex.url({ query: { tab: 'connect' } }));
};
</script>

<template>
    <section
        v-if="visible"
        class="fixed end-4 bottom-4 z-40 flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-4 rounded-xl bg-popover p-5 text-popover-foreground shadow-md dark:border dark:border-border"
        role="dialog"
        aria-labelledby="mcp-prompt-title"
        aria-describedby="mcp-prompt-body"
        data-testid="mcp-prompt"
    >
        <div class="flex items-start gap-3">
            <span
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"
                aria-hidden="true"
            >
                <IconMcp class="size-5" />
            </span>
            <div class="flex min-w-0 flex-col gap-1">
                <h2
                    id="mcp-prompt-title"
                    class="text-base font-medium text-foreground"
                >
                    {{ $t('mcp.prompt.title') }}
                </h2>
                <p id="mcp-prompt-body" class="text-sm text-muted-foreground">
                    {{ $t('mcp.prompt.body') }}
                </p>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <Button
                type="button"
                variant="ghost"
                data-testid="mcp-prompt-dismiss"
                @click="dismiss"
            >
                {{ $t('mcp.prompt.not_now') }}
            </Button>
            <Button
                type="button"
                data-testid="mcp-prompt-connect"
                @click="connect"
            >
                {{ $t('mcp.prompt.connect') }}
            </Button>
        </div>
    </section>
</template>
