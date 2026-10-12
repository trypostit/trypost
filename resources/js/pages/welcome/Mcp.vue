<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    IconBulb,
    IconCalendarPlus,
    IconChartLine,
    IconCircleCheck,
    IconCircleCheckFilled,
    IconCopy,
    IconListDetails,
} from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref, type Component } from 'vue';

import AppLogo from '@/components/AppLogo.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
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
    { key: 'analytics', icon: IconChartLine },
    { key: 'queue', icon: IconListDetails },
    { key: 'ideas', icon: IconBulb },
];

const {
    active: activeBenefit,
    select: selectBenefit,
    pause: pauseBenefits,
    resume: resumeBenefits,
} = useAutoRotate(benefits.length, 5000);

const activeKey = computed((): string => benefits[activeBenefit.value].key);

const connectedClient = computed(
    (): ConnectedClient | null => props.connectedClients[0] ?? null,
);

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
            @mouseenter="pauseBenefits"
            @mouseleave="resumeBenefits"
        >
            <ul
                class="flex flex-col gap-1 rounded-xl border border-border bg-card p-2 sm:rounded-e-none sm:border-e-0"
                data-testid="welcome-mcp-benefits"
            >
                <li v-for="(benefit, index) in benefits" :key="benefit.key">
                    <button
                        type="button"
                        :aria-pressed="index === activeBenefit"
                        :class="[
                            'flex w-full cursor-pointer items-start gap-3 rounded-lg p-3 text-start transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring',
                            index === activeBenefit
                                ? 'bg-primary-subtle dark:bg-primary-selected'
                                : 'hover:bg-muted',
                        ]"
                        :data-testid="`welcome-mcp-benefit-${benefit.key}`"
                        @click="selectBenefit(index)"
                    >
                        <span
                            class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-card text-primary-text shadow-xs"
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
                            <span class="text-xs text-muted-foreground">
                                {{
                                    $t(`welcome.mcp.benefits.${benefit.key}.hint`)
                                }}
                            </span>
                        </span>
                    </button>
                </li>
            </ul>

            <div
                class="grid bg-primary-subtle p-5 max-sm:hidden sm:p-6 dark:bg-primary-selected"
                aria-hidden="true"
            >
                <Transition
                    mode="out-in"
                    enter-active-class="transition-opacity duration-300"
                    leave-active-class="transition-opacity duration-300"
                    enter-from-class="opacity-0"
                    leave-to-class="opacity-0"
                >
                    <div
                        :key="activeKey"
                        class="flex flex-col justify-center gap-3"
                        :data-testid="`welcome-mcp-chat-${activeKey}`"
                    >
                        <p
                            class="ms-10 self-end rounded-2xl rounded-ee-sm bg-card px-4 py-3 text-sm text-foreground shadow-xs"
                        >
                            {{ $t(`welcome.mcp.benefits.${activeKey}.ask`) }}
                        </p>
                        <div
                            class="me-10 flex flex-col gap-2 self-start rounded-2xl rounded-es-sm bg-primary-strong px-4 py-3 text-sm text-primary-strong-foreground shadow-xs dark:bg-card dark:text-foreground"
                        >
                            <span
                                class="flex items-center gap-1.5 text-xs font-medium opacity-90"
                            >
                                <AppLogo variant="mark" class="text-[14px]" />
                                TryPost
                            </span>
                            <p>
                                {{
                                    $t(`welcome.mcp.benefits.${activeKey}.reply`)
                                }}
                            </p>
                        </div>
                    </div>
                </Transition>
            </div>
        </article>

        <div class="mt-4 flex flex-col items-center gap-3">
            <div class="flex flex-wrap items-center justify-center gap-1">
                <Button
                    v-for="client in mcpClients"
                    :key="client.id"
                    as="a"
                    :href="client.settingsUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    variant="ghost"
                    size="sm"
                    :data-testid="`welcome-mcp-open-${client.id}`"
                >
                    <img
                        :src="client.logo"
                        alt=""
                        :class="['size-4', client.logoClass]"
                    />
                    {{ $t('welcome.mcp.open', { client: client.label }) }}
                </Button>
                <Popover>
                    <PopoverTrigger as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-testid="welcome-mcp-view-prompt"
                        >
                            {{ $t('welcome.mcp.view_prompt') }}
                        </Button>
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
