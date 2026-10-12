<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    IconCircleCheck,
    IconCircleCheckFilled,
    IconCopy,
} from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { mcpClients } from '@/lib/mcpClients';
import { copyToClipboard } from '@/lib/utils';
import { index as postsIndex } from '@/routes/app/posts';

interface ConnectedClient {
    client_id: string;
    name: string;
}

const props = defineProps<{
    prompt: string;
    connectedClients: ConnectedClient[];
}>();

usePoll(2000, { only: ['connectedClients'] });

const connectedClient = computed(
    (): ConnectedClient | null => props.connectedClients[0] ?? null,
);

const steps = ['step_copy', 'step_paste', 'step_login'] as const;

const copied = ref(false);
let copiedTimeout: ReturnType<typeof setTimeout> | null = null;

const copyPrompt = async (): Promise<void> => {
    const didCopy = await copyToClipboard(props.prompt, undefined, {
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

const goToSchedule = (): void => {
    router.visit(postsIndex.url());
};
</script>

<template>
    <Head :title="$t('welcome.mcp.title')" />

    <WelcomeLayout
        :title="$t('welcome.mcp.title')"
        :description="$t('welcome.mcp.description')"
    >
        <article
            class="grid overflow-hidden rounded-xl text-start sm:grid-cols-[2fr_3fr]"
        >
            <div
                class="flex flex-col justify-between gap-6 bg-primary-subtle p-5 sm:p-6 dark:bg-primary-selected"
            >
                <ol class="flex flex-col gap-4">
                    <li
                        v-for="(step, index) in steps"
                        :key="step"
                        class="flex items-start gap-3 text-sm leading-6 font-medium text-foreground"
                    >
                        <span
                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-card text-xs font-semibold text-primary-text tabular-nums"
                            aria-hidden="true"
                        >
                            {{ index + 1 }}
                        </span>
                        {{ $t(`welcome.mcp.${step}`) }}
                    </li>
                </ol>

                <p
                    class="flex items-center gap-2 text-sm"
                    data-testid="welcome-mcp-status"
                    aria-live="polite"
                >
                    <template v-if="connectedClient">
                        <IconCircleCheckFilled
                            class="size-4 shrink-0 text-green-500"
                        />
                        <span class="font-medium text-foreground">{{
                            $t('welcome.mcp.connected', {
                                client: connectedClient.name,
                            })
                        }}</span>
                    </template>
                    <template v-else>
                        <Spinner class="shrink-0 text-primary-text" />
                        <span class="text-muted-foreground">{{
                            $t('welcome.mcp.waiting')
                        }}</span>
                    </template>
                </p>
            </div>

            <div
                class="flex min-w-0 flex-col rounded-b-xl border border-border bg-card max-sm:border-t-0 sm:rounded-e-xl sm:rounded-es-none sm:border-s-0"
                data-testid="welcome-mcp-prompt"
            >
                <div
                    class="flex items-center justify-between gap-3 border-b border-border px-5 py-3 sm:px-6"
                >
                    <span class="text-sm font-medium text-muted-foreground">
                        {{ $t('welcome.mcp.prompt_label') }}
                    </span>
                    <div class="flex gap-1">
                        <Button
                            v-for="client in mcpClients"
                            :key="client.id"
                            as="a"
                            :href="client.settingsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            variant="ghost"
                            size="sm"
                            :aria-label="
                                $t('welcome.mcp.open', { client: client.label })
                            "
                            :title="
                                $t('welcome.mcp.open', { client: client.label })
                            "
                            :data-testid="`welcome-mcp-open-${client.id}`"
                        >
                            <img
                                :src="client.logo"
                                alt=""
                                :class="['size-4', client.logoClass]"
                            />
                            {{ client.label }}
                        </Button>
                    </div>
                </div>
                <pre
                    dir="ltr"
                    class="max-h-48 overflow-y-auto px-5 py-4 font-mono text-xs leading-5 whitespace-pre-wrap text-muted-foreground [mask-image:linear-gradient(to_bottom,black_75%,transparent)] sm:max-h-56 sm:px-6"
                    >{{ prompt }}</pre
                >
            </div>
        </article>

        <template #actions>
            <WelcomeContinueButton
                v-if="connectedClient"
                :disabled="false"
                testid="welcome-mcp-continue"
                @continue="goToSchedule"
            />
            <template v-else>
                <Button
                    type="button"
                    size="lg"
                    class="w-full"
                    data-testid="welcome-mcp-copy"
                    @click="copyPrompt"
                >
                    <template v-if="copied">
                        <IconCircleCheck class="size-4" />
                        {{ $t('common.actions.copied') }}
                    </template>
                    <template v-else>
                        <IconCopy class="size-4" />
                        {{ $t('welcome.mcp.copy_prompt') }}
                    </template>
                </Button>
                <Link
                    :href="postsIndex.url()"
                    class="text-sm font-medium text-muted-foreground transition-control hover:text-foreground"
                    data-testid="welcome-mcp-skip"
                >
                    {{ $t('welcome.mcp.skip') }}
                </Link>
            </template>
        </template>
    </WelcomeLayout>
</template>
