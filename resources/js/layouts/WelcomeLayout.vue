<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { computed } from 'vue';

import { updateLanguage } from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
import AppLogo from '@/components/AppLogo.vue';
import LanguageSelect from '@/components/LanguageSelect.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import Toast from '@/components/Toast.vue';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import {
    goals as goalsRoute,
    persona as personaRoute,
    plan as planRoute,
    software as softwareRoute,
} from '@/routes/app/welcome';
import type { Language, SharedData, WelcomeStep } from '@/types';

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
    }>(),
    {
        title: undefined,
        description: undefined,
        step: undefined,
        size: '3xl',
    },
);

const page = usePage<SharedData>();

const languages = computed<Language[]>(
    () => (page.props.languages as Language[] | undefined) ?? [],
);

const changeLanguage = (locale: string): void => {
    router.put(
        updateLanguage.url(),
        { locale },
        { preserveScroll: true, preserveState: true },
    );
};

const steps = [
    { key: 'persona', route: personaRoute() },
    { key: 'goals', route: goalsRoute() },
    { key: 'software', route: softwareRoute() },
    { key: 'plan', route: planRoute() },
] as const;

const currentIndex = computed(() =>
    steps.findIndex((entry) => entry.key === props.step),
);

const isMobile = useBelowBreakpoint('sm');

const showsStepsAboveTitle = computed(
    () => isMobile.value && Boolean(props.title || props.description),
);

const previousStep = computed(() =>
    currentIndex.value > 0 ? steps[currentIndex.value - 1] : null,
);
</script>

<template>
    <div class="flex min-h-svh flex-col bg-muted">
        <header
            class="flex items-center justify-between gap-4 px-4 pt-4 sm:grid sm:grid-cols-[1fr_auto_1fr] sm:px-8 sm:pt-8 lg:px-12"
        >
            <div class="flex min-w-0 items-center gap-3">
                <Link
                    v-if="previousStep"
                    :href="previousStep.route"
                    :aria-label="$t('welcome.back')"
                    :title="$t('welcome.back')"
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    data-testid="welcome-back"
                >
                    <IconArrowLeft class="size-4 rtl:rotate-180" />
                </Link>
                <AppLogo class="text-[24px] max-sm:[&>span]:hidden" />
            </div>

            <Teleport
                v-if="currentIndex >= 0"
                to="#welcome-steps-mobile"
                defer
                :disabled="!showsStepsAboveTitle"
            >
                <nav
                    :aria-label="$t('welcome.progress')"
                    class="flex justify-center"
                >
                    <ol class="flex items-center gap-1.5">
                        <li
                            v-for="(entry, index) in steps"
                            :key="entry.key"
                            :title="$t(`welcome.steps.${entry.key}`)"
                            :data-testid="`welcome-step-${entry.key}`"
                            :aria-current="
                                index === currentIndex ? 'step' : undefined
                            "
                            :aria-label="
                                $t('welcome.step_of', {
                                    step: String(index + 1),
                                    total: String(steps.length),
                                })
                            "
                            :class="[
                                'size-1.5 rounded-full transition-[background-color] duration-200 ease-out',
                                index === currentIndex
                                    ? 'bg-foreground'
                                    : 'bg-border-strong',
                            ]"
                        />
                    </ol>
                </nav>
            </Teleport>
            <span v-else />

            <div class="flex items-center justify-end gap-2">
                <LanguageSelect
                    :model-value="String(page.props.locale)"
                    :languages="languages"
                    :label="$t('settings.preferences.language.heading')"
                    testid="welcome-language"
                    trigger-class="bg-card max-sm:px-2 max-sm:[&>span]:sr-only"
                    @update:model-value="changeLanguage"
                />
                <ThemeToggle compact-on-mobile />
            </div>
        </header>

        <main
            :class="[
                'flex flex-1 flex-col px-4 py-12 sm:px-8 lg:px-12',
                $slots.actions &&
                    'max-sm:pb-[calc(7rem+env(safe-area-inset-bottom))]',
            ]"
        >
            <div
                :class="[
                    'mx-auto my-auto flex w-full flex-col items-center gap-8',
                    maxWidthClass[size],
                ]"
            >
                <div
                    v-if="title || description"
                    class="flex flex-col gap-2 text-center"
                >
                    <div
                        v-if="currentIndex >= 0"
                        id="welcome-steps-mobile"
                        class="mb-2 sm:hidden"
                    />
                    <h1
                        v-if="title"
                        class="motion-auth-reveal mx-auto max-w-xl font-heading text-[28px] leading-9 font-medium text-balance text-foreground sm:text-[32px] sm:leading-10"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="mx-auto max-w-xl text-base text-pretty text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>

                <div class="w-full">
                    <slot />
                </div>

                <div
                    v-if="$slots.actions"
                    class="flex w-full max-w-sm flex-col items-center gap-3 max-sm:fixed max-sm:inset-x-0 max-sm:bottom-0 max-sm:z-10 max-sm:max-w-none max-sm:border-t max-sm:border-border max-sm:bg-card max-sm:px-4 max-sm:pt-3 max-sm:pb-[max(0.75rem,env(safe-area-inset-bottom))]"
                    data-testid="welcome-actions"
                >
                    <slot name="actions" />
                </div>
            </div>
        </main>

        <Toast />
    </div>
</template>
