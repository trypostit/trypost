<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import { goalMeta, personaMeta, welcomeOptionMeta } from '@/lib/welcomeOptions';
import type { WelcomeStep, WelcomeSummary } from '@/types';

const props = defineProps<{
    summary: WelcomeSummary;
    step?: WelcomeStep;
}>();

const persona = computed(() =>
    props.summary.persona
        ? {
              meta: welcomeOptionMeta(personaMeta, props.summary.persona),
              labelKey: `welcome.personas.${props.summary.persona}`,
          }
        : null,
);

const goals = computed(() =>
    props.summary.goals.map((goal) => ({
        value: goal,
        meta: welcomeOptionMeta(goalMeta, goal),
        labelKey: `welcome.goals.${goal}`,
    })),
);

const rows = computed(() => [
    { key: 'persona' as const, done: persona.value !== null },
    { key: 'goals' as const, done: goals.value.length > 0 },
    { key: 'connect' as const, done: props.summary.networks.length > 0 },
]);

const isCurrent = (key: WelcomeStep): boolean => props.step === key;

const EMPTY_NETWORK_SLOTS = 3;

const PENDING_CLASS =
    'inline-flex items-center rounded-lg border border-dashed border-border-strong px-3 py-1 text-xs font-medium text-muted-foreground';
</script>

<template>
    <div class="flex flex-col gap-6" data-testid="welcome-preview">
        <h2
            class="motion-auth-reveal font-heading text-[32px] leading-[1.12] font-normal tracking-[-0.03em] text-balance text-foreground"
        >
            {{ $t('welcome.preview.heading') }}
        </h2>

        <div
            class="motion-auth-fade overflow-hidden rounded-xl border border-border bg-card"
        >
            <ul class="divide-y divide-border">
                <li
                    v-for="row in rows"
                    :key="row.key"
                    :class="[
                        'p-4 transition-control',
                        isCurrent(row.key) ? 'bg-muted' : '',
                    ]"
                    :data-testid="`welcome-preview-${row.key}`"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span
                            class="text-xs font-medium text-muted-foreground"
                        >
                            {{ $t(`welcome.steps.${row.key}`) }}
                        </span>
                        <span
                            v-if="row.done"
                            class="inline-flex size-5 items-center justify-center rounded-full bg-success-subtle text-success-text"
                            aria-hidden="true"
                        >
                            <IconCheck class="size-3" stroke-width="3" />
                        </span>
                    </div>

                    <div class="mt-2.5">
                        <template v-if="row.key === 'persona'">
                            <span
                                v-if="persona"
                                class="inline-flex max-w-full items-center gap-2 rounded-lg border border-border-strong bg-card py-1 ps-1 pe-3"
                            >
                                <span
                                    :class="[
                                        'inline-flex size-6 shrink-0 items-center justify-center rounded-md',
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
                                <span class="truncate text-sm font-medium">{{
                                    $t(persona.labelKey)
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
                                    class="inline-flex max-w-full items-center gap-2 rounded-lg border border-border-strong bg-card py-1 ps-1 pe-3"
                                >
                                    <span
                                        :class="[
                                            'inline-flex size-6 shrink-0 items-center justify-center rounded-md',
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
                                    <span class="truncate text-sm font-medium">{{
                                        $t(goal.labelKey)
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
                                enter-active-class="animate-in zoom-in-95 fade-in duration-200 motion-reduce:animate-none"
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
                                                    class="relative cursor-default"
                                                    :aria-label="
                                                        network.display_label
                                                    "
                                                    :data-testid="`welcome-preview-network-${network.id}`"
                                                >
                                                    <ChannelAvatar
                                                        :platform="
                                                            network.platform
                                                        "
                                                        :src="
                                                            network.avatar_url
                                                        "
                                                        :name="
                                                            network.display_label
                                                        "
                                                        :size="40"
                                                        ring="card"
                                                        :reserve-space="false"
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
                                    class="size-10 shrink-0 rounded-lg border border-dashed border-border-strong"
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
