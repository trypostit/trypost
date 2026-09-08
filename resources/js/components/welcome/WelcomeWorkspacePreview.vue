<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconCheck } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Avatar } from '@/components/ui/avatar';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { goalMeta, personaMeta, welcomeOptionMeta } from '@/lib/welcomeOptions';
import type { Auth, WelcomeSummary } from '@/types';

const props = defineProps<{
    summary: WelcomeSummary;
    step?: number;
}>();

const page = usePage();

const workspace = computed(() => (page.props.auth as Auth).currentWorkspace);

const workspaceName = computed(
    () => workspace.value?.name ?? (page.props.auth as Auth).user.first_name,
);

const persona = computed(() =>
    props.summary.persona
        ? {
              meta: welcomeOptionMeta(personaMeta, props.summary.persona),
              label: trans(`welcome.personas.${props.summary.persona}`),
          }
        : null,
);

const goals = computed(() =>
    props.summary.goals.map((goal) => ({
        value: goal,
        meta: welcomeOptionMeta(goalMeta, goal),
        label: trans(`welcome.goals.${goal}`),
    })),
);

const rows = computed(() => [
    { key: 'persona', step: 1, done: persona.value !== null },
    { key: 'goals', step: 2, done: goals.value.length > 0 },
    { key: 'connect', step: 4, done: props.summary.networks.length > 0 },
]);

const isCurrent = (rowStep: number): boolean => props.step === rowStep;

const EMPTY_NETWORK_SLOTS = 3;

const PENDING_CLASS =
    'inline-flex items-center rounded-full border-2 border-dashed border-foreground/25 px-3 py-1 text-xs font-semibold text-muted-foreground';
</script>

<template>
    <div class="flex flex-col gap-6" data-testid="welcome-preview">
        <h2
            class="text-3xl leading-[1.1] tracking-tight text-balance text-foreground"
            style="font-family: var(--font-display)"
        >
            {{ $t('welcome.preview.heading') }}
        </h2>

        <div
            class="overflow-hidden rounded-2xl border-2 border-foreground bg-card shadow-md"
        >
            <div
                class="flex items-center gap-3 border-b-2 border-foreground bg-violet-50 px-5 py-4"
            >
                <Avatar
                    :src="workspace?.logo_url ?? null"
                    :name="workspaceName"
                    class="size-11 rounded-xl border-2 border-foreground shadow-2xs"
                    fallback-class="bg-violet-200 text-sm font-bold text-violet-800"
                />
                <div class="min-w-0">
                    <p class="truncate font-bold text-foreground">
                        {{ workspaceName }}
                    </p>
                    <p class="text-xs font-medium text-muted-foreground">
                        {{ $t('welcome.preview.workspace') }}
                    </p>
                </div>
            </div>

            <ul class="divide-y divide-foreground/10">
                <li
                    v-for="row in rows"
                    :key="row.key"
                    :class="[
                        'px-5 py-4 transition-colors duration-300 motion-reduce:transition-none',
                        isCurrent(row.step) ? 'bg-violet-50/70' : '',
                    ]"
                    :data-testid="`welcome-preview-${row.key}`"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span
                            class="text-xs font-semibold text-muted-foreground"
                        >
                            {{ $t(`welcome.steps.${row.key}`) }}
                        </span>
                        <span
                            v-if="row.done"
                            class="inline-flex size-5 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 text-emerald-800"
                            aria-hidden="true"
                        >
                            <IconCheck class="size-3" stroke-width="3" />
                        </span>
                    </div>

                    <div class="mt-2.5">
                        <template v-if="row.key === 'persona'">
                            <span
                                v-if="persona"
                                class="inline-flex max-w-full items-center gap-2 rounded-full border-2 border-foreground bg-card py-1 ps-1 pe-3 shadow-2xs"
                            >
                                <span
                                    :class="[
                                        'inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground',
                                        persona.meta.badge,
                                    ]"
                                >
                                    <component
                                        :is="persona.meta.icon"
                                        :class="[
                                            persona.meta.iconClass,
                                            'size-3.5',
                                        ]"
                                        stroke-width="2.25"
                                    />
                                </span>
                                <span class="truncate text-sm font-bold">{{
                                    persona.label
                                }}</span>
                            </span>
                            <span v-else :class="PENDING_CLASS">
                                {{ $t('welcome.preview.pending') }}
                            </span>
                        </template>

                        <template v-else-if="row.key === 'goals'">
                            <div
                                v-if="goals.length > 0"
                                class="flex flex-wrap gap-1.5"
                            >
                                <span
                                    v-for="goal in goals"
                                    :key="goal.value"
                                    class="inline-flex max-w-full items-center gap-2 rounded-full border-2 border-foreground bg-card py-1 ps-1 pe-3 shadow-2xs"
                                >
                                    <span
                                        :class="[
                                            'inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground',
                                            goal.meta.badge,
                                        ]"
                                    >
                                        <component
                                            :is="goal.meta.icon"
                                            :class="[
                                                goal.meta.iconClass,
                                                'size-3.5',
                                            ]"
                                            stroke-width="2.25"
                                        />
                                    </span>
                                    <span class="truncate text-sm font-bold">{{
                                        goal.label
                                    }}</span>
                                </span>
                            </div>
                            <span v-else :class="PENDING_CLASS">
                                {{ $t('welcome.preview.pending') }}
                            </span>
                        </template>

                        <template v-else-if="row.key === 'connect'">
                            <TransitionGroup
                                v-if="summary.networks.length > 0"
                                tag="div"
                                class="flex flex-wrap gap-2.5"
                                enter-active-class="animate-in zoom-in-50 fade-in duration-300 motion-reduce:animate-none"
                            >
                                <span
                                    v-for="network in summary.networks"
                                    :key="network.id"
                                    class="inline-flex"
                                >
                                    <TooltipProvider :delay-duration="200">
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <button
                                                    type="button"
                                                    class="cursor-default"
                                                    :aria-label="
                                                        network.display_label
                                                    "
                                                >
                                                    <PlatformLogo
                                                        :platform="
                                                            network.platform
                                                        "
                                                        size="sm"
                                                        :title="null"
                                                    />
                                                </button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <div
                                                    class="space-y-0.5 text-xs"
                                                >
                                                    <p class="font-semibold">
                                                        {{
                                                            network.display_label
                                                        }}<span
                                                            v-if="
                                                                network.username
                                                            "
                                                            class="font-normal opacity-80"
                                                            >&nbsp;·&nbsp;@{{
                                                                network.username
                                                            }}</span
                                                        >
                                                    </p>
                                                    <p class="opacity-70">
                                                        {{
                                                            getPlatformLabel(
                                                                network.platform,
                                                            )
                                                        }}
                                                    </p>
                                                </div>
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </span>
                            </TransitionGroup>
                            <div v-else class="flex items-center gap-2.5">
                                <span
                                    v-for="slot in EMPTY_NETWORK_SLOTS"
                                    :key="slot"
                                    class="size-10 shrink-0 rounded-xl border-2 border-dashed border-foreground/25"
                                    aria-hidden="true"
                                />
                                <span
                                    class="text-xs font-medium text-muted-foreground"
                                >
                                    {{ $t('welcome.preview.networks_empty') }}
                                </span>
                            </div>
                        </template>

                        <template v-else>
                            <span :class="PENDING_CLASS">
                                {{ $t('welcome.preview.pending') }}
                            </span>
                        </template>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
