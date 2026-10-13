<script setup lang="ts">
import { Head, Link, router, useHttp, usePoll } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconArrowUp,
    IconBulb,
    IconCalendarPlus,
    IconChevronRight,
    IconCircleCheckFilled,
    IconListDetails,
    IconMicrophone,
    IconPlus,
    IconSparkles,
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

import BottomSheet from '@/components/BottomSheet.vue';
import McpConnectDialog from '@/components/mcp/McpConnectDialog.vue';
import { Button } from '@/components/ui/button';
import McpChatExample from '@/components/welcome/mcp/McpChatExample.vue';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import { useAutoRotate } from '@/composables/useAutoRotate';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import {
    mcpClients,
    otherMcpClient,
    type McpClient,
    type McpClientId,
} from '@/lib/mcpClients';
import { index as postsIndex } from '@/routes/app/posts';
import {
    connectClick,
    skip as skipMcp,
} from '@/routes/app/welcome/mcp';
import { fireCelebration } from '@/utils/confetti';

interface ConnectedClient {
    client_id: string;
    name: string;
}

const props = defineProps<{
    mcpUrl: string;
    connectedClients: ConnectedClient[];
}>();

const { stop: stopPolling } = usePoll(
    2000,
    { only: ['connectedClients'] },
    { autoStart: props.connectedClients.length === 0 },
);

const benefits: { key: string; icon: Component }[] = [
    { key: 'ideas', icon: IconBulb },
    { key: 'schedule', icon: IconCalendarPlus },
    { key: 'queue', icon: IconListDetails },
    { key: 'analytics', icon: IconTrendingUp },
];

const {
    active: activeBenefit,
    select: selectBenefit,
    pause: pauseBenefits,
    resume: resumeBenefits,
    stop: stopBenefits,
} = useAutoRotate(benefits.length, 8000);

/** Picking a tab means the user wants to read it: the rotation stops for good. */
const chooseBenefit = (index: number): void => {
    selectBenefit(index);
    stopBenefits();
};

const activeKey = computed((): string => benefits[activeBenefit.value].key);

const pills = useTemplateRef<HTMLElement>('pills');

const revealActivePill = async (): Promise<void> => {
    await nextTick();

    const row = pills.value;
    const pill = row?.children[activeBenefit.value];

    if (!row || !pill || row.clientWidth === 0) {
        return;
    }

    const rowBox = row.getBoundingClientRect();
    const pillBox = pill.getBoundingClientRect();

    row.scrollBy({
        left:
            pillBox.left + pillBox.width / 2 - (rowBox.left + rowBox.width / 2),
        behavior: 'smooth',
    });
};

watch(activeBenefit, revealActivePill);

const connectedClient = computed(
    (): ConnectedClient | null => props.connectedClients[0] ?? null,
);

const connectableClients: McpClient[] = [...mcpClients, otherMcpClient];

const activeClient = ref<McpClient>(mcpClients[0]);
const dialogOpen = ref(false);
const connectClickHttp = useHttp<{ client: McpClientId }>({ client: 'claude' });

const trackConnectClick = (client: McpClient): void => {
    connectClickHttp.client = client.id;
    connectClickHttp.post(connectClick.url()).catch(() => {});
};

const connectWith = (client: McpClient): void => {
    activeClient.value = client;
    dialogOpen.value = true;
    trackConnectClick(client);
};

const aiPickerOpen = ref(false);

const openAiPicker = (): void => {
    aiPickerOpen.value = true;
};

/** Phones pick the app in a sheet first; the steps open once it has closed. */
const pickAi = async (client: McpClient): Promise<void> => {
    aiPickerOpen.value = false;
    await nextTick();
    connectWith(client);
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

    stopPolling();
    dialogOpen.value = false;
    await nextTick();

    stopConfetti = fireCelebration(connectedStatus.value ?? undefined);
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
            <p
                class="sr-only"
                aria-live="polite"
                data-testid="welcome-mcp-announcement"
            >
                {{
                    connectedClient
                        ? $t('welcome.mcp.connected', {
                              client: connectedClient.name,
                          })
                        : ''
                }}
            </p>
            <div
                class="flex flex-col overflow-hidden rounded-xl border border-border-strong bg-card"
                data-testid="welcome-mcp-window"
                @mouseenter="pauseBenefits"
                @mouseleave="resumeBenefits"
                @focusin="pauseBenefits"
                @focusout="resumeBenefits"
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
                    role="group"
                    :aria-label="$t('welcome.mcp.title')"
                >
                    <button
                        v-for="(benefit, index) in benefits"
                        :key="benefit.key"
                        type="button"
                        :aria-pressed="index === activeBenefit"
                        :class="[
                            'flex w-full cursor-pointer items-start gap-3 rounded-lg p-2.5 text-start sm:p-3 transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                            index === activeBenefit
                                ? 'bg-primary-subtle dark:bg-primary-selected'
                                : 'hover:bg-muted',
                        ]"
                        :data-testid="`welcome-mcp-benefit-${benefit.key}`"
                        @click="chooseBenefit(index)"
                    >
                        <span
                            :class="[
                                'inline-flex size-8 shrink-0 items-center justify-center rounded-lg border transition-control',
                                index === activeBenefit
                                    ? 'border-transparent bg-primary-strong text-primary-strong-foreground dark:bg-primary dark:text-primary-foreground'
                                    : 'border-border bg-card text-primary-text',
                            ]"
                            aria-hidden="true"
                            :data-testid="`welcome-mcp-benefit-icon-${benefit.key}`"
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
                    class="flex gap-2 overflow-x-auto border-b border-border bg-muted/40 p-3 [mask-image:linear-gradient(to_right,black_85%,transparent)] md:hidden rtl:[mask-image:linear-gradient(to_left,black_85%,transparent)]"
                    role="group"
                    :aria-label="$t('welcome.mcp.title')"
                >
                    <button
                        v-for="(benefit, index) in benefits"
                        :key="benefit.key"
                        type="button"
                        :aria-pressed="index === activeBenefit"
                        :class="[
                            'inline-flex h-8 shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3 text-sm font-medium whitespace-nowrap transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                            index === activeBenefit
                                ? 'border-primary-strong bg-primary-subtle text-primary-text dark:bg-primary-selected'
                                : 'border-border bg-card text-muted-foreground',
                        ]"
                        :data-testid="`welcome-mcp-pill-${benefit.key}`"
                        @click="chooseBenefit(index)"
                    >
                        <component :is="benefit.icon" class="size-4" />
                        {{ $t(`welcome.mcp.benefits.${benefit.key}.title`) }}
                    </button>
                </div>

                <div class="flex flex-col">
                    <div class="grid flex-1 px-4 pt-4 pb-3">
                        <McpChatExample
                            v-for="benefit in benefits"
                            :key="benefit.key"
                            :benefit="benefit.key"
                            class="invisible [grid-area:1/1]"
                            aria-hidden="true"
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
                    <div class="px-4 pb-4 max-sm:hidden" aria-hidden="true">
                        <div
                            class="flex h-10 items-center gap-2.5 rounded-full border border-border bg-card ps-3 pe-1.5"
                            data-testid="welcome-mcp-composer"
                        >
                            <IconPlus class="size-5 shrink-0 text-foreground" stroke-width="1.5" />
                            <span
                                class="min-w-0 flex-1 truncate text-sm text-muted-foreground"
                            >
                                {{ $t('welcome.mcp.composer') }}
                            </span>
                            <IconMicrophone
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span
                                class="inline-flex size-7 shrink-0 items-center justify-center rounded-full bg-secondary text-muted-foreground"
                            >
                                <IconArrowUp class="size-4" />
                            </span>
                        </div>
                    </div>
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
                    aria-hidden="true"
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
                <div class="flex gap-2 max-sm:hidden">
                    <Button
                        v-for="client in connectableClients"
                        :key="client.id"
                        type="button"
                        size="lg"
                        :data-testid="`welcome-mcp-connect-${client.id}`"
                        @click="connectWith(client)"
                    >
                        <img
                            :src="client.monoLogo"
                            alt=""
                            class="size-4 invert dark:invert-0"
                        />
                        {{
                            client.labelKey
                                ? $t('welcome.mcp.connect_other')
                                : $t('welcome.mcp.connect_with', {
                                      client: client.label,
                                  })
                        }}
                    </Button>
                </div>
                <div class="grid w-full grid-cols-2 gap-2 sm:hidden">
                    <Button
                        type="button"
                        size="lg"
                        data-testid="welcome-mcp-connect-ai"
                        @click="openAiPicker"
                    >
                        <IconSparkles class="size-4" />
                        {{ $t('welcome.mcp.connect_ai') }}
                    </Button>
                    <Button
                        as-child
                        variant="secondary"
                        size="lg"
                    >
                        <Link
                            :href="skipMcp.url()"
                            data-testid="welcome-mcp-skip-link"
                        >
                            {{ $t('welcome.mcp.skip') }}
                            <IconArrowRight class="size-4 rtl:rotate-180" />
                        </Link>
                    </Button>
                </div>
                <Button
                    as-child
                    variant="secondary"
                    size="lg"
                    class="max-sm:hidden sm:fixed sm:end-8 sm:bottom-8 lg:end-12"
                >
                    <Link
                        :href="skipMcp.url()"
                        data-testid="welcome-mcp-skip"
                    >
                        {{ $t('welcome.mcp.skip') }}
                        <IconArrowRight class="size-4 rtl:rotate-180" />
                    </Link>
                </Button>
            </template>
        </template>

        <BottomSheet
            v-model:open="aiPickerOpen"
            :title="$t('welcome.mcp.pick_ai')"
            test-id="welcome-mcp-ai-picker"
        >
            <ul class="flex flex-col gap-2 px-4 pb-4">
                <li v-for="client in connectableClients" :key="client.id">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 rounded-xl border border-border bg-card p-3 text-start transition-control hover:bg-muted"
                        :data-testid="`welcome-mcp-pick-${client.id}`"
                        @click="pickAi(client)"
                    >
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted"
                        >
                            <img
                                :src="client.logo"
                                alt=""
                                :class="['size-5 object-contain', client.logoClass]"
                            />
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="text-sm font-medium text-foreground">
                                {{ client.labelKey ? $t(client.label) : client.label }}
                            </span>
                            <span
                                v-if="client.labelKey"
                                class="text-sm text-muted-foreground"
                            >
                                {{ $t('mcp.other_hint') }}
                            </span>
                        </span>
                        <IconChevronRight
                            class="size-4 shrink-0 text-muted-foreground rtl:rotate-180"
                        />
                    </button>
                </li>
            </ul>
        </BottomSheet>

        <McpConnectDialog
            v-model:open="dialogOpen"
            :client="activeClient"
            :mcp-url="mcpUrl"
        />
    </WelcomeLayout>
</template>
