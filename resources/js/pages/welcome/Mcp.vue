<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    IconBulb,
    IconCalendarPlus,
    IconCheck,
    IconCircleCheck,
    IconCircleCheckFilled,
    IconCopy,
    IconListDetails,
    IconTrendingUp,
} from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref, type Component } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
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
const copiedOnce = ref(false);

const stepBadgeClass = (done: boolean): string[] => [
    'inline-flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
    done
        ? 'bg-green-500 text-white'
        : 'bg-card text-primary-text',
];
let copiedTimeout: ReturnType<typeof setTimeout> | null = null;

const copyPrompt = async (): Promise<void> => {
    const didCopy = await copyToClipboard(props.prompt, undefined, {
        showSuccessToast: false,
    });

    if (!didCopy) {
        return;
    }

    copied.value = true;
    copiedOnce.value = true;

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
        <article
            class="grid gap-3 text-start sm:gap-4 lg:grid-cols-[minmax(0,1fr)_18rem] lg:gap-0"
        >
            <div
                class="grid overflow-hidden rounded-xl border border-border bg-card lg:grid-cols-[16rem_minmax(0,1fr)] lg:rounded-e-none lg:border-e-0"
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

            <ol
                class="flex flex-col gap-4 rounded-xl bg-primary-subtle p-4 sm:gap-5 sm:p-5 lg:rounded-s-none lg:p-6 dark:bg-primary-selected"
                data-testid="welcome-mcp-steps"
            >
                <li class="flex items-start gap-3">
                    <span :class="stepBadgeClass(copiedOnce)">
                        <IconCheck v-if="copiedOnce" class="size-3.5" />
                        <template v-else>1</template>
                    </span>
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-sm font-medium text-foreground">
                            {{ $t('welcome.mcp.step_copy') }}
                        </span>
                        <Popover>
                            <PopoverTrigger as-child>
                                <button
                                    type="button"
                                    class="cursor-pointer self-start text-sm text-primary-text underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                    data-testid="welcome-mcp-view-prompt"
                                >
                                    {{ $t('welcome.mcp.view_prompt') }}
                                </button>
                            </PopoverTrigger>
                            <PopoverContent
                                side="top"
                                class="w-96 max-w-[calc(100vw-2rem)] p-0"
                            >
                                <pre
                                    dir="ltr"
                                    class="max-h-72 overflow-y-auto p-4 font-mono text-xs leading-5 whitespace-pre-wrap text-foreground"
                                    data-testid="welcome-mcp-prompt"
                                    >{{ prompt }}</pre
                                >
                            </PopoverContent>
                        </Popover>
                    </div>
                </li>

                <li class="flex items-start gap-3">
                    <span :class="stepBadgeClass(false)">2</span>
                    <div class="flex min-w-0 flex-col gap-2">
                        <span class="text-sm font-medium text-foreground">
                            {{ $t('welcome.mcp.step_paste') }}
                        </span>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="client in mcpClients"
                                :key="client.id"
                                as="a"
                                :href="client.settingsUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                variant="outline"
                                size="sm"
                                class="bg-card"
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
                </li>

                <li class="flex items-start gap-3">
                    <span :class="stepBadgeClass(connectedClient !== null)">
                        <IconCheck v-if="connectedClient" class="size-3.5" />
                        <template v-else>3</template>
                    </span>
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <span class="text-sm font-medium text-foreground">
                            {{ $t('welcome.mcp.step_login') }}
                        </span>
                        <p
                            class="flex items-center gap-1.5 text-sm"
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
                                <Spinner class="size-3.5 shrink-0 text-primary-text" />
                                <span class="text-muted-foreground">{{
                                    $t('welcome.mcp.waiting')
                                }}</span>
                            </template>
                        </p>
                    </div>
                </li>
            </ol>
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
