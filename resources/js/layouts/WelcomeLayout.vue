<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { computed } from 'vue';

import AuthLanguageSwitcher from '@/components/auth/AuthLanguageSwitcher.vue';
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
import type { WelcomeSummary } from '@/types';

const maxWidthClass = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
    '3xl': 'max-w-3xl',
    '4xl': 'max-w-4xl',
    '5xl': 'max-w-5xl',
    '6xl': 'max-w-6xl',
    '7xl': 'max-w-7xl',
} as const;

type MaxWidthSize = keyof typeof maxWidthClass;

const props = withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        step?: number;
        totalSteps?: number;
        size?: MaxWidthSize;
        centered?: boolean;
    }>(),
    {
        title: undefined,
        description: undefined,
        step: undefined,
        totalSteps: 5,
        size: '3xl',
        centered: false,
    },
);

const page = usePage();

const summary = computed(
    () => (page.props.welcome as WelcomeSummary | undefined) ?? null,
);

const steps = computed(() => [
    { key: 'persona', route: personaRoute() },
    { key: 'goals', route: goalsRoute() },
    { key: 'referral_source', route: referralSourceRoute() },
    { key: 'connect', route: connectRoute() },
    { key: 'plan', route: planRoute() },
]);

const previousStep = computed(() =>
    props.step !== undefined && props.step > 1
        ? steps.value[props.step - 2]
        : null,
);
</script>

<template>
    <div
        :class="[
            'min-h-svh bg-background',
            summary
                ? 'lg:grid lg:grid-cols-[minmax(0,1fr)_28rem] xl:grid-cols-[minmax(0,1fr)_32rem] 2xl:grid-cols-[minmax(0,1fr)_36rem]'
                : '',
        ]"
    >
        <div class="relative flex min-h-svh flex-col">
            <header
                class="flex items-center justify-between gap-4 px-6 pt-6 md:px-10 lg:px-14"
            >
                <nav
                    v-if="step !== undefined"
                    class="flex items-center gap-3"
                    :aria-label="$t('welcome.progress')"
                >
                    <span
                        class="text-sm font-semibold whitespace-nowrap text-muted-foreground tabular-nums"
                    >
                        {{
                            $t('welcome.step_of', {
                                step: String(step),
                                total: String(totalSteps),
                            })
                        }}
                    </span>
                    <ol class="flex items-center gap-1.5">
                        <li
                            v-for="(entry, index) in steps"
                            :key="entry.key"
                            class="flex h-6 items-center"
                            :title="$t(`welcome.steps.${entry.key}`)"
                            :data-testid="`welcome-step-${index + 1}`"
                            :aria-current="
                                index + 1 === step ? 'step' : undefined
                            "
                            :aria-label="
                                index + 1 === step
                                    ? $t('welcome.step_current', {
                                          step: String(index + 1),
                                      })
                                    : undefined
                            "
                        >
                            <span
                                :class="[
                                    'h-1.5 w-6 rounded-full transition-colors sm:w-8',
                                    index + 1 <= step
                                        ? 'bg-primary'
                                        : 'bg-foreground/15',
                                ]"
                            />
                        </li>
                    </ol>
                </nav>
                <span v-else />

                <AuthLanguageSwitcher />
            </header>

            <main
                :class="[
                    'flex flex-1 flex-col px-6 pt-8 pb-8 md:px-10 lg:px-14 lg:pt-10',
                    summary ? '' : 'items-center',
                ]"
            >
                <div
                    :class="[
                        'my-auto w-full',
                        maxWidthClass[size],
                        centered || !summary ? 'mx-auto text-center' : '',
                    ]"
                >
                    <div
                        v-if="title || description"
                        class="flex flex-col gap-3"
                    >
                        <h1 v-if="title" class="h3 text-foreground">
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            :class="[
                                'text-base text-pretty text-muted-foreground',
                                centered || !summary
                                    ? 'mx-auto max-w-xl'
                                    : 'max-w-prose',
                            ]"
                        >
                            {{ description }}
                        </p>
                    </div>

                    <div class="mt-6 flex flex-col gap-8">
                        <slot />
                    </div>
                </div>
            </main>

            <footer
                v-if="$slots.actions || previousStep"
                class="sticky bottom-0 z-10 mt-auto border-t border-foreground/10 bg-background/90 px-6 py-4 backdrop-blur-sm md:px-10 lg:px-14"
            >
                <div
                    class="flex w-full flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                >
                    <Button
                        v-if="previousStep"
                        as-child
                        variant="outline"
                        size="lg"
                        class="w-full sm:w-auto"
                    >
                        <Link
                            :href="previousStep.route"
                            data-testid="welcome-back"
                        >
                            <IconArrowLeft
                                class="size-4 rtl:rotate-180"
                                stroke-width="2.25"
                            />
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
            class="relative hidden overflow-hidden border-s-2 border-foreground bg-accent lg:sticky lg:top-0 lg:flex lg:h-svh lg:flex-col lg:justify-center lg:px-10 xl:px-14"
        >
            <div
                class="pointer-events-none absolute -top-24 -right-24 size-[440px] rounded-full bg-violet-200/50 blur-3xl"
            />
            <div
                class="pointer-events-none absolute -bottom-32 -left-32 size-[440px] rounded-full bg-fuchsia-200/40 blur-3xl"
            />
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.06]"
                style="
                    background-image: radial-gradient(
                        circle,
                        #0a0a0a 1px,
                        transparent 1px
                    );
                    background-size: 28px 28px;
                "
            />

            <div class="relative mx-auto w-full max-w-lg">
                <WelcomeWorkspacePreview :summary="summary" :step="step" />
            </div>
        </aside>
    </div>
</template>
