<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { computed } from 'vue';

import LocaleSwitcher from '@/components/LocaleSwitcher.vue';
import Toast from '@/components/Toast.vue';
import { Button } from '@/components/ui/button';
import WelcomeWorkspacePreview from '@/components/welcome/WelcomeWorkspacePreview.vue';
import {
    connect as connectRoute,
    goals as goalsRoute,
    persona as personaRoute,
    plan as planRoute,
    referralSource as referralSourceRoute,
} from '@/routes/app/welcome';
import type { SharedData, WelcomeStep } from '@/types';

const maxWidthClass = {
    lg: 'max-w-lg',
    '3xl': 'max-w-3xl',
    '4xl': 'max-w-4xl',
    '5xl': 'max-w-5xl',
} as const;

type MaxWidthSize = keyof typeof maxWidthClass;

const props = withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        step?: WelcomeStep;
        size?: MaxWidthSize;
        centered?: boolean;
    }>(),
    {
        title: undefined,
        description: undefined,
        step: undefined,
        size: '3xl',
        centered: false,
    },
);

const page = usePage<SharedData>();

const summary = computed(() => page.props.welcome ?? null);

const steps = [
    { key: 'persona', route: personaRoute() },
    { key: 'goals', route: goalsRoute() },
    { key: 'referral_source', route: referralSourceRoute() },
    { key: 'connect', route: connectRoute() },
    { key: 'plan', route: planRoute() },
] as const;

const currentIndex = computed(() =>
    steps.findIndex((entry) => entry.key === props.step),
);

const previousStep = computed(() =>
    currentIndex.value > 0 ? steps[currentIndex.value - 1] : null,
);

const alignCenter = computed(() => props.centered || summary.value === null);
</script>

<template>
    <div
        :class="[
            'min-h-svh bg-muted',
            summary
                ? 'lg:grid lg:grid-cols-[minmax(0,1fr)_28rem] xl:grid-cols-[minmax(0,1fr)_32rem] 2xl:grid-cols-[minmax(0,1fr)_36rem]'
                : '',
        ]"
    >
        <div class="relative flex min-h-svh min-w-0 flex-col">
            <header
                class="flex items-center justify-between gap-4 px-4 pt-4 sm:px-8 sm:pt-6 lg:px-12"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <img
                        src="/images/trypost/icon.png"
                        alt="TryPost"
                        class="motion-auth-logo h-8 w-auto shrink-0"
                    />
                    <nav
                        v-if="currentIndex >= 0"
                        class="flex min-w-0 items-center gap-3"
                        :aria-label="$t('welcome.progress')"
                    >
                        <span
                            class="hidden text-sm font-medium whitespace-nowrap text-muted-foreground tabular-nums sm:inline"
                        >
                            {{
                                $t('welcome.step_of', {
                                    step: String(currentIndex + 1),
                                    total: String(steps.length),
                                })
                            }}
                        </span>
                        <ol class="flex items-center gap-1">
                            <li
                                v-for="(entry, index) in steps"
                                :key="entry.key"
                                class="flex h-6 items-center"
                                :title="$t(`welcome.steps.${entry.key}`)"
                                :data-testid="`welcome-step-${entry.key}`"
                                :aria-current="
                                    index === currentIndex ? 'step' : undefined
                                "
                            >
                                <span
                                    :class="[
                                        'h-1 w-6 rounded-full transition-[background-color] duration-200 ease-out sm:w-8',
                                        index <= currentIndex
                                            ? 'bg-primary-strong'
                                            : 'bg-border-strong',
                                    ]"
                                />
                            </li>
                        </ol>
                    </nav>
                </div>

                <LocaleSwitcher />
            </header>

            <main
                :class="[
                    'flex flex-1 flex-col px-4 pt-8 pb-8 sm:px-8 lg:px-12 lg:pt-10',
                    summary ? '' : 'items-center',
                ]"
            >
                <div
                    :class="[
                        'my-auto w-full',
                        maxWidthClass[size],
                        alignCenter ? 'mx-auto text-center' : '',
                    ]"
                >
                    <div
                        v-if="title || description"
                        class="flex flex-col gap-2"
                    >
                        <h1
                            v-if="title"
                            class="motion-auth-reveal font-heading text-2xl font-medium text-balance text-foreground sm:text-[28px] sm:leading-8"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            :class="[
                                'text-base text-pretty text-muted-foreground',
                                alignCenter ? 'mx-auto max-w-xl' : 'max-w-prose',
                            ]"
                        >
                            {{ description }}
                        </p>
                    </div>

                    <div class="mt-8 flex flex-col gap-8">
                        <slot />
                    </div>
                </div>
            </main>

            <footer
                v-if="$slots.actions || previousStep"
                class="sticky bottom-0 z-10 mt-auto border-t border-border bg-muted/90 px-4 py-4 backdrop-blur-sm sm:px-8 lg:px-12"
            >
                <div
                    class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                >
                    <Button
                        v-if="previousStep"
                        as-child
                        variant="ghost"
                        size="lg"
                        class="w-full sm:w-auto"
                    >
                        <Link
                            :href="previousStep.route"
                            data-testid="welcome-back"
                        >
                            <IconArrowLeft class="size-4 rtl:rotate-180" />
                            {{ $t('welcome.back') }}
                        </Link>
                    </Button>

                    <div class="sm:ms-auto">
                        <slot name="actions" />
                    </div>
                </div>
            </footer>

            <Toast />
        </div>

        <aside
            v-if="summary"
            class="hidden p-8 ps-0 lg:sticky lg:top-0 lg:block lg:h-svh"
        >
            <div
                class="flex h-full flex-col justify-center overflow-y-auto rounded-[20px] bg-primary-subtle px-10 py-10 xl:px-14"
            >
                <div class="mx-auto w-full max-w-lg">
                    <WelcomeWorkspacePreview :summary="summary" :step="step" />
                </div>
            </div>
        </aside>
    </div>
</template>
