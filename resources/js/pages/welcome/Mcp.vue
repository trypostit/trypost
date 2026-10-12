<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    IconBulb,
    IconCalendarPlus,
    IconCircleCheck,
    IconCircleCheckFilled,
    IconCopy,
    IconListDetails,
    IconTrendingUp,
} from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref, type Component } from 'vue';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import McpChatExample from '@/components/welcome/mcp/McpChatExample.vue';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import { useAutoRotate } from '@/composables/useAutoRotate';
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

const benefits: { key: string; icon: Component }[] = [
    { key: 'schedule', icon: IconCalendarPlus },
    { key: 'analytics', icon: IconTrendingUp },
    { key: 'queue', icon: IconListDetails },
    { key: 'ideas', icon: IconBulb },
];

const {
    active: activeBenefit,
    select: selectBenefit,
    pause: pauseBenefits,
    resume: resumeBenefits,
} = useAutoRotate(benefits.length, 6000);

const activeKey = computed((): string => benefits[activeBenefit.value].key);

const connectedClient = computed(
    (): ConnectedClient | null => props.connectedClients[0] ?? null,
);

const copied = ref(false);
const started = ref(false);

const startConnecting = (): void => {
    started.value = true;
};

let copiedTimeout: ReturnType<typeof setTimeout> | null = null;

const copyPrompt = async (): Promise<void> => {
    const didCopy = await copyToClipboard(props.prompt, undefined, {
        showSuccessToast: false,
    });

    if (!didCopy) {
        return;
    }

    copied.value = true;
    startConnecting();

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
        size="5xl"
    >
        <article class="text-start">
            <div
                class="grid overflow-hidden rounded-xl border border-border bg-card lg:grid-cols-[18rem_minmax(0,1fr)]"
                @mouseenter="pauseBenefits"
                @mouseleave="resumeBenefits"
            >
                <div
                    class="flex flex-col gap-1 p-2"
                    role="tablist"
                    aria-orientation="vertical"
                    :aria-label="$t('welcome.mcp.title')"
                >
                    <button
                        v-for="(benefit, index) in benefits"
                        :key="benefit.key"
                        type="button"
                        role="tab"
                        :aria-selected="index === activeBenefit"
                        :class="[
                            'flex w-full cursor-pointer items-start gap-3 rounded-lg p-2.5 text-start sm:p-3 transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                            index === activeBenefit
                                ? 'bg-primary-subtle dark:bg-primary-selected'
                                : 'hover:bg-muted',
                        ]"
                        :data-testid="`welcome-mcp-benefit-${benefit.key}`"
                        @click="selectBenefit(index)"
                    >
                        <span
                            class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-card text-primary-text"
                            aria-hidden="true"
                        >
                            <component :is="benefit.icon" class="size-4" />
                        </span>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="text-sm font-medium text-foreground">
                                {{
                                    $t(`welcome.mcp.benefits.${benefit.key}.title`)
                                }}
                            </span>
                            <span
                                class="text-xs text-muted-foreground max-sm:hidden"
                            >
                                {{
                                    $t(`welcome.mcp.benefits.${benefit.key}.hint`)
                                }}
                            </span>
                        </span>
                    </button>
                </div>

                <div
                    class="hidden border-s border-border bg-muted p-5 lg:grid"
                    aria-hidden="true"
                >
                    <McpChatExample
                        v-for="benefit in benefits"
                        :key="benefit.key"
                        :benefit="benefit.key"
                        class="invisible [grid-area:1/1]"
                    />
                    <Transition
                        mode="out-in"
                        enter-active-class="transition-opacity duration-300"
                        leave-active-class="transition-opacity duration-300"
                        enter-from-class="opacity-0"
                        leave-to-class="opacity-0"
                    >
                        <McpChatExample
                            :key="activeKey"
                            :benefit="activeKey"
                            class="[grid-area:1/1]"
                            :data-testid="`welcome-mcp-chat-${activeKey}`"
                        />
                    </Transition>
                </div>
            </div>

        </article>

        <template #actions>
            <template v-if="connectedClient">
                <p
                    class="flex items-center gap-1.5 text-sm"
                    data-testid="welcome-mcp-status"
                    aria-live="polite"
                >
                    <IconCircleCheckFilled class="size-4 shrink-0 text-green-500" />
                    <span class="font-medium text-foreground">{{
                        $t('welcome.mcp.connected', {
                            client: connectedClient.name,
                        })
                    }}</span>
                </p>
                <WelcomeContinueButton
                    :disabled="false"
                    testid="welcome-mcp-continue"
                    @continue="goToSchedule"
                />
            </template>
            <template v-else>
                <p
                    v-if="started"
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                    data-testid="welcome-mcp-status"
                    aria-live="polite"
                >
                    <Spinner class="size-3.5 shrink-0 text-primary-text" />
                    {{ $t('welcome.mcp.waiting') }}
                </p>
                <div class="grid w-full grid-cols-2 gap-2">
                    <Button
                        v-for="client in mcpClients"
                        :key="client.id"
                        as="a"
                        :href="client.promptUrl(prompt)"
                        target="_blank"
                        rel="noopener noreferrer"
                        size="lg"
                        variant="secondary"
                        :data-testid="`welcome-mcp-open-${client.id}`"
                        @click="startConnecting"
                    >
                        <img
                            :src="client.logo"
                            alt=""
:class="['size-4', client.logoClass]"
                        />
                        {{ $t('welcome.mcp.open', { client: client.label }) }}
                    </Button>
                </div>
                <div
                    class="flex items-center justify-center gap-4 text-sm font-medium text-muted-foreground"
                >
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center gap-1.5 transition-control hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        data-testid="welcome-mcp-copy"
                        @click="copyPrompt"
                    >
                        <template v-if="copied">
                            <IconCircleCheck class="size-4 text-green-500" />
                            {{ $t('common.actions.copied') }}
                        </template>
                        <template v-else>
                            <IconCopy class="size-4" />
                            {{ $t('welcome.mcp.copy_prompt') }}
                        </template>
                    </button>
                    <span aria-hidden="true" class="h-4 w-px bg-border-strong" />
                    <Link
                        :href="postsIndex.url()"
                        class="transition-control hover:text-foreground"
                        data-testid="welcome-mcp-skip"
                    >
                        {{ $t('welcome.mcp.skip') }}
                    </Link>
                </div>
            </template>
        </template>
    </WelcomeLayout>
</template>
