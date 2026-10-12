<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    IconBulb,
    IconCalendarPlus,
    IconCircleCheckFilled,
    IconListDetails,
    IconTrendingUp,
} from '@tabler/icons-vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    useTemplateRef,
    watch,
    type Component,
} from 'vue';

import { Button } from '@/components/ui/button';
import McpChatExample from '@/components/welcome/mcp/McpChatExample.vue';
import McpConnectDialog from '@/components/welcome/mcp/McpConnectDialog.vue';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import { useAutoRotate } from '@/composables/useAutoRotate';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { mcpClients, type McpClient } from '@/lib/mcpClients';
import { index as postsIndex } from '@/routes/app/posts';
import { fireConfetti } from '@/utils/confetti';

interface ConnectedClient {
    client_id: string;
    name: string;
}

const props = defineProps<{
    mcpUrl: string;
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

const pills = useTemplateRef<HTMLElement>('pills');

const revealActivePill = async (): Promise<void> => {
    await nextTick();

    const pill = pills.value?.querySelector<HTMLElement>('[aria-selected="true"]');

    if (pill && pills.value?.offsetParent !== null) {
        pills.value?.scrollTo({
            left: pill.offsetLeft - pills.value.offsetWidth / 2 + pill.offsetWidth / 2,
            behavior: 'smooth',
        });
    }
};

watch(activeBenefit, revealActivePill);

const connectedClient = computed(
    (): ConnectedClient | null => props.connectedClients[0] ?? null,
);

const activeClient = ref<McpClient>(mcpClients[0]);
const dialogOpen = ref(false);
const connectWith = (client: McpClient): void => {
    activeClient.value = client;
    dialogOpen.value = true;
};

const connectedStatus = useTemplateRef<HTMLElement>('connectedStatus');
let stopConfetti = (): void => {};

/**
 * The poll notices the connection while the user is still in the other tab:
 * close the steps and celebrate on the page's own connected state.
 */
const celebrateConnection = async (
    client: ConnectedClient | null,
    previous: ConnectedClient | null,
): Promise<void> => {
    if (!client || previous) {
        return;
    }

    dialogOpen.value = false;
    await nextTick();

    const cannon = (x: number, angle: number): (() => void) =>
        fireConfetti({
            origin: { x, y: 1 },
            angle,
            spread: 55,
            particleCount: 160,
            startVelocity: 48,
            duration: 4200,
        });

    const stops = [
        fireConfetti({
            origin: connectedStatus.value ?? { x: 0.5, y: 0.8 },
            particleCount: 200,
            spread: 120,
            startVelocity: 26,
            duration: 3600,
        }),
        cannon(0, 60),
        cannon(1, 120),
    ];
    const timeouts = [250, 500].map((delay) =>
        setTimeout(() => {
            stops.push(cannon(0, 65), cannon(1, 115));
        }, delay),
    );

    stopConfetti = () => {
        timeouts.forEach(clearTimeout);
        stops.forEach((stop) => stop());
    };
};

watch(connectedClient, celebrateConnection);

onBeforeUnmount(() => {
    stopConfetti();
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
        size="4xl"
        actions-size="4xl"
    >
        <article class="text-start">
            <div
                class="flex flex-col overflow-hidden rounded-xl border border-border-strong bg-card"
                data-testid="welcome-mcp-window"
                @mouseenter="pauseBenefits"
                @mouseleave="resumeBenefits"
            >
                <div
                    class="flex h-9 shrink-0 items-center gap-1.5 border-b border-border bg-muted px-3.5"
                    aria-hidden="true"
                >
                    <span class="size-3 rounded-full bg-[#ff5f57]" />
                    <span class="size-3 rounded-full bg-[#febc2e]" />
                    <span class="size-3 rounded-full bg-[#28c840]" />
                </div>

                <div
                    class="grid flex-1 lg:grid-cols-[18rem_minmax(0,1fr)]"
                >
                <div
                    class="flex flex-col gap-1 border-border bg-muted/40 p-2 max-md:hidden max-lg:border-b lg:border-e"
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
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    $t(`welcome.mcp.benefits.${benefit.key}.hint`)
                                }}
                            </span>
                        </span>
                    </button>
                </div>

                <div
                    ref="pills"
                    class="flex gap-2 overflow-x-auto border-b border-border bg-muted/40 p-3 md:hidden"
                    role="tablist"
                    :aria-label="$t('welcome.mcp.title')"
                >
                    <button
                        v-for="(benefit, index) in benefits"
                        :key="benefit.key"
                        type="button"
                        role="tab"
                        :aria-selected="index === activeBenefit"
                        :class="[
                            'inline-flex h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3 text-sm font-medium whitespace-nowrap transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                            index === activeBenefit
                                ? 'border-primary-strong bg-primary-subtle text-primary-text dark:bg-primary-selected'
                                : 'border-border bg-card text-muted-foreground',
                        ]"
                        :data-testid="`welcome-mcp-pill-${benefit.key}`"
                        @click="selectBenefit(index)"
                    >
                        <component :is="benefit.icon" class="size-4" />
                        {{ $t(`welcome.mcp.benefits.${benefit.key}.title`) }}
                    </button>
                </div>

                <div class="grid p-4 sm:p-5" aria-hidden="true">
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
            </div>
        </article>

        <template #actions>
            <template v-if="connectedClient">
                <WelcomeContinueButton
                    :disabled="false"
                    testid="welcome-mcp-continue"
                    @continue="goToSchedule"
                />
                <p
                    ref="connectedStatus"
                    class="flex items-center gap-1.5 text-sm font-medium text-foreground"
                    data-testid="welcome-mcp-status"
                    aria-live="polite"
                >
                    <IconCircleCheckFilled class="size-4 shrink-0 text-green-500" />
                    {{
                        $t('welcome.mcp.connected', {
                            client: connectedClient.name,
                        })
                    }}
                </p>
            </template>
            <template v-else>
                <div class="flex flex-wrap justify-center gap-2">
                    <Button
                        v-for="client in mcpClients"
                        :key="client.id"
                        type="button"
                        size="lg"
                        variant="secondary"
                        class="max-sm:w-full"
                        :data-testid="`welcome-mcp-connect-${client.id}`"
                        @click="connectWith(client)"
                    >
                        <img
                            :src="client.logo"
                            alt=""
                            :class="['size-4', client.logoClass]"
                        />
                        {{
                            $t('welcome.mcp.connect_with', {
                                client: client.label,
                            })
                        }}
                    </Button>
                </div>
                <Link
                    :href="postsIndex.url()"
                    class="text-sm font-medium text-muted-foreground transition-control hover:text-foreground"
                    data-testid="welcome-mcp-skip"
                >
                    {{ $t('welcome.mcp.skip') }}
                </Link>
            </template>
        </template>

        <McpConnectDialog
            v-model:open="dialogOpen"
            :client="activeClient"
            :mcp-url="mcpUrl"
        />
    </WelcomeLayout>
</template>
