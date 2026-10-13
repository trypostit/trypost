<script setup lang="ts">
import { useHttp, usePage } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconArrowRight,
    IconCheck,
    IconCircleCheckFilled,
    IconHelpCircle,
    IconLoader2,
    IconPencil,
} from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import { DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Stepper } from '@/components/ui/stepper';
import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import { DOCS_URL } from '@/lib/docs';
import { hourWindow, MAX_GOAL } from '@/lib/postingSchedule';
import { orderedWeekdays } from '@/preferences';
import { generate } from '@/routes/app/channels/posting-schedule';
import type { SidebarChannel } from '@/types/channel';
import type {
    ChannelScheduleState,
    PostingSchedule,
} from '@/types/posting-schedule';
import { fireCelebration } from '@/utils/confetti';

const props = defineProps<{
    accountId: string;
}>();

const emit = defineEmits<{
    done: [];
    customize: [];
}>();

type Choice = 1 | 3 | 5 | 'custom';

type WindowParams = { day: string; start: string; end: string };

const CUSTOM_START = 6;

const page = usePage();
const channel = computed(() =>
    ((page.props.channels as SidebarChannel[]) ?? []).find(
        (item) => item.id === props.accountId,
    ),
);

const CHANNEL_WAIT_MS = 8000;

let channelWaitTimer: ReturnType<typeof setTimeout> | null = null;

const clearChannelWait = (): void => {
    if (channelWaitTimer !== null) {
        clearTimeout(channelWaitTimer);
        channelWaitTimer = null;
    }
};

onMounted(() => {
    if (channel.value) {
        return;
    }

    channelWaitTimer = setTimeout(() => {
        if (!channel.value) {
            emit('done');
        }
    }, CHANNEL_WAIT_MS);
});

const avatar = ref<HTMLElement | null>(null);

let confettiFired = false;
let stopConfetti = (): void => {};

watch(avatar, (element) => {
    if (!element || confettiFired) {
        return;
    }

    confettiFired = true;

    const frame = requestAnimationFrame(() => {
        stopConfetti = fireCelebration(element);
    });

    stopConfetti = () => cancelAnimationFrame(frame);
});

onUnmounted(() => {
    clearChannelWait();
    stopConfetti();
});

const step = ref<'goal' | 'recommended'>('goal');

const showGoalStep = (): void => {
    step.value = 'goal';
};

const choice = ref<Choice>(3);
const custom = ref(CUSTOM_START);
const schedule = ref<PostingSchedule | null>(null);
const saving = ref(false);
const helpOpen = ref(false);

const closeHelp = (): void => {
    helpOpen.value = false;
};

const http = useHttp<Record<string, any>, ChannelScheduleState>({});

const options: { value: Choice; label: string; badge: string }[] = [
    {
        value: 1,
        label: 'steady',
        badge: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    },
    {
        value: 3,
        label: 'presence',
        badge: 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
    },
    {
        value: 5,
        label: 'heights',
        badge: 'bg-teal-100 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
    },
    {
        value: 'custom',
        label: 'custom',
        badge: 'bg-pink-100 text-pink-600 dark:bg-pink-500/15 dark:text-pink-300',
    },
];

const goal = computed(() =>
    choice.value === 'custom' ? custom.value : choice.value,
);

const perWeek = (count: number): string =>
    transChoice('channels.goal_dialog.per_week', count, { count: String(count) });

const setChoice = (value: unknown): void => {
    choice.value = value === 'custom' ? 'custom' : (Number(value) as Choice);
};

const save = async (): Promise<void> => {
    saving.value = true;

    try {
        const response = await http
            .transform(() => ({ mode: 'goal', goal: goal.value }))
            .post(generate.url(props.accountId));

        if (!response) {
            toast.error(trans('channels.goal_dialog.failed'));
            return;
        }

        schedule.value = response.posting_schedule;
        step.value = 'recommended';
    } catch {
        toast.error(trans('channels.goal_dialog.failed'));
    } finally {
        saving.value = false;
    }
};

const weekdayShort = (day: number): string =>
    dayjs().locale(activeLocale.value.toLowerCase()).day(day).format('ddd');

const clock = (time: string): string => date.formatClockTime(time);

const windowSegments = (
    template: string,
    params: WindowParams,
): { text: string; strong: boolean }[] =>
    template
        .split(/(:day|:start|:end)/)
        .filter((segment) => segment !== '')
        .map((segment) => {
            const key = segment.slice(1) as keyof WindowParams;

            return segment.startsWith(':') && key in params
                ? { text: params[key], strong: key !== 'day' }
                : { text: segment, strong: false };
        });

const rows = computed(() =>
    orderedWeekdays()
        .flatMap((day) =>
            (schedule.value ?? []).filter((entry) => entry.day === day),
        )
        .flatMap((entry) =>
            entry.enabled
                ? entry.times.map((time) => {
                      const window = hourWindow(time);

                      return {
                          key: `${entry.day}-${time}`,
                          params: {
                              day: weekdayShort(entry.day),
                              start: clock(window.start),
                              end: clock(window.end),
                          },
                      };
                  })
                : [],
        ),
);
</script>

<template>
    <div v-if="!channel" class="flex items-center justify-center py-16">
        <IconLoader2 class="size-6 animate-spin text-muted-foreground" />
    </div>

    <div v-else class="flex flex-col sm:min-h-[660px]">
        <div class="flex flex-1 flex-col px-6 pt-12 pb-6 sm:px-10 sm:pt-20 sm:pb-10">
            <div class="mx-auto flex w-full max-w-[640px] flex-col items-center">
                <div ref="avatar" class="shrink-0" data-testid="goal-avatar">
                    <ChannelAvatar
                        :status="channel.status"
                        :account-id="channel.id"
                        :platform="channel.platform"
                        :src="channel.avatar_url"
                        :name="channel.display_name || channel.username"
                        :size="64"
                        :reserve-space="false"
                    >
                        <IconCircleCheckFilled
                            v-if="step === 'goal'"
                            class="absolute -start-2.5 -top-2.5 size-7 rounded-full bg-background text-success"
                        />
                    </ChannelAvatar>
                </div>

                <template v-if="step === 'goal'">
                    <div class="mt-8 text-center" data-testid="goal-flow">
                        <DialogTitle class="text-[22px]">{{ $t('channels.goal_dialog.title') }}</DialogTitle>
                        <p
                            class="mt-2 text-[15px] text-muted-foreground"
                            data-testid="goal-description"
                        >
                            {{ $t('channels.goal_dialog.description') }}
                        </p>
                    </div>

                    <RadioGroup
                        :model-value="String(choice)"
                        class="mt-6 w-full max-w-[530px] gap-2"
                        @update:model-value="setChoice"
                    >
                        <div
                            v-for="option in options"
                            :key="option.value"
                            class="rounded-lg border border-border-strong transition-control has-[[data-state=checked]]:border-primary-strong"
                            :data-testid="`goal-option-${option.value}`"
                        >
                            <Label
                                :for="`goal-radio-${option.value}`"
                                class="flex min-h-[62px] cursor-pointer items-center gap-4 py-2 ps-2 pe-6"
                            >
                                <span
                                    class="flex size-11 shrink-0 items-center justify-center rounded-md text-lg font-normal"
                                    :class="option.badge"
                                    data-testid="goal-option-badge"
                                >
                                    <template v-if="option.value !== 'custom'">{{ option.value }}x</template>
                                    <template v-else-if="choice === 'custom'">{{ custom }}x</template>
                                    <IconPencil v-else class="size-5" />
                                </span>
                                <span
                                    class="flex min-w-0 flex-1 flex-col text-base font-medium text-foreground sm:block"
                                    data-testid="goal-option-label"
                                >
                                    <span>{{ $t(`channels.goal_dialog.options.${option.label}`) }}</span>
                                    <span
                                        v-if="option.value !== 'custom'"
                                        class="text-sm font-normal whitespace-nowrap text-muted-foreground sm:text-base sm:font-medium sm:text-foreground"
                                    ><span class="hidden sm:inline"> · </span>{{ perWeek(option.value) }}</span>
                                </span>
                                <Stepper
                                    v-if="option.value === 'custom' && choice === 'custom'"
                                    v-model="custom"
                                    :max="MAX_GOAL"
                                    :decrease-label="$t('channels.settings_page.goal_decrease')"
                                    :increase-label="$t('channels.settings_page.goal_increase')"
                                    decrease-test-id="goal-custom-decrease"
                                    increase-test-id="goal-custom-increase"
                                    value-test-id="goal-custom-value"
                                    :aria-label="perWeek(custom)"
                                    @click.prevent
                                />
                                <RadioGroupItem
                                    :id="`goal-radio-${option.value}`"
                                    :value="String(option.value)"
                                    data-testid="goal-option-radio"
                                />
                            </Label>
                        </div>
                    </RadioGroup>
                </template>

                <template v-else>
                    <div class="mt-8 text-center" data-testid="goal-recommended">
                        <DialogTitle class="text-[22px]">{{ $t('channels.goal_dialog.recommended_title') }}</DialogTitle>
                        <p
                            class="mt-2 text-[15px] text-muted-foreground"
                            data-testid="goal-description"
                        >
                            {{ $t('channels.goal_dialog.recommended_description') }}
                            <br />
                            {{ $t('channels.goal_dialog.recommended_note') }}
                            <a
                                :href="DOCS_URL"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-primary-text underline underline-offset-2 hover:text-primary-text-hover"
                                data-testid="goal-learn-more"
                            >{{ $t('channels.goal_dialog.learn_more') }}</a>
                        </p>
                    </div>

                    <ul class="mt-6 w-full max-w-[530px] space-y-2">
                        <li
                            v-for="row in rows"
                            :key="row.key"
                            class="flex min-h-[52px] items-center gap-3 rounded-lg border border-border-strong px-4 py-2 text-[15px] text-foreground"
                            data-testid="goal-recommended-row"
                        >
                            <IconCheck class="size-4 shrink-0" />
                            <span>
                                <template
                                    v-for="(segment, index) in windowSegments($t('channels.goal_dialog.window'), row.params)"
                                    :key="index"
                                >
                                    <strong v-if="segment.strong" class="font-semibold">{{ segment.text }}</strong>
                                    <template v-else>{{ segment.text }}</template>
                                </template>
                            </span>
                        </li>
                    </ul>
                </template>
            </div>
        </div>

        <div
            class="flex flex-col-reverse items-center justify-between gap-3 border-border px-6 pt-0 pb-6 sm:flex-row sm:border-t sm:px-8 sm:py-4"
            data-testid="goal-footer"
        >
            <template v-if="step === 'goal'">
                <Popover v-model:open="helpOpen">
                    <PopoverTrigger as-child>
                        <Button
                            variant="ghost"
                            size="lg"
                            class="px-2 font-normal data-[state=open]:bg-accent sm:-ms-2 sm:text-[15px]"
                            data-testid="goal-help"
                        >
                            <IconHelpCircle class="size-4" />
                            {{ $t('channels.goal_dialog.help') }}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent
                        side="top"
                        align="start"
                        :side-offset="8"
                        class="w-[300px] p-4"
                        data-testid="goal-help-popover"
                    >
                        <p class="text-sm leading-relaxed text-foreground">
                            {{ $t('channels.goal_dialog.help_body') }}
                        </p>
                        <div class="mt-3 flex items-center justify-end gap-2">
                            <Button
                                as="a"
                                variant="ghost"
                                :href="DOCS_URL"
                                target="_blank"
                                rel="noopener noreferrer"
                                data-testid="goal-help-learn-more"
                            >
                                {{ $t('channels.goal_dialog.learn_more') }}
                            </Button>
                            <Button data-testid="goal-help-close" @click="closeHelp">
                                {{ $t('channels.goal_dialog.done') }}
                            </Button>
                        </div>
                    </PopoverContent>
                </Popover>
                <Button size="lg" class="w-full sm:w-auto" :disabled="saving" data-testid="goal-next" @click="save">
                    <IconLoader2 v-if="saving" class="size-4 animate-spin" />
                    {{ $t('channels.goal_dialog.next') }}
                    <IconArrowRight v-if="!saving" class="size-4 rtl:rotate-180" />
                </Button>
            </template>

            <template v-else>
                <Button
                    variant="ghost"
                    size="lg"
                    class="px-2 font-normal sm:-ms-2 sm:text-[15px]"
                    data-testid="goal-change"
                    @click="showGoalStep"
                >
                    <IconArrowLeft class="size-4 rtl:rotate-180" />
                    {{ $t('channels.goal_dialog.change_goal') }}
                </Button>
                <div class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row sm:items-center">
                    <Button
                        size="lg"
                        variant="ghost"
                        class="w-full font-normal sm:w-auto sm:text-[15px]"
                        data-testid="goal-customize"
                        @click="emit('customize')"
                    >
                        {{ $t('channels.goal_dialog.customize') }}
                    </Button>
                    <Button size="lg" class="w-full sm:w-auto" data-testid="goal-done" @click="emit('done')">
                        {{ $t('channels.goal_dialog.done') }}
                    </Button>
                </div>
            </template>
        </div>
    </div>
</template>
