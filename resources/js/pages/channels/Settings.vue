<script setup lang="ts">
import { Head, Link, useHttp } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconChevronDown,
    IconMinus,
    IconPlus,
    IconTrash,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import PostingScheduleGrid from '@/components/channels/PostingScheduleGrid.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    canAddTo,
    cleared,
    emptySchedule,
    MAX_GOAL,
    MAX_TIMES_PER_DAY,
    slotCount,
    targetDays,
    withTime,
    type DayTarget,
} from '@/lib/postingSchedule';
import { orderedWeekdays } from '@/preferences';
import { copy, generate, update } from '@/routes/app/channels/posting-schedule';
import { channels as channelsRoute } from '@/routes/app/workspace';
import type {
    ChannelScheduleState,
    PostingSchedule,
    TimezoneOption,
} from '@/types/posting-schedule';
import type { ConnectedAccount } from '@/types/social-account';

interface OtherChannel {
    id: string;
    display_name: string | null;
    username: string;
    platform: string;
    avatar_url: string | null;
}

const DEFAULT_GOAL = 3;

const props = defineProps<{
    channel: ConnectedAccount;
    schedule: ChannelScheduleState;
    timezones: TimezoneOption[];
    otherChannels: OtherChannel[];
}>();

const state = ref<ChannelScheduleState>({ ...props.schedule });
const http = useHttp<Record<string, any>, ChannelScheduleState>({});

const currentSchedule = computed<PostingSchedule>(
    () => state.value.posting_schedule ?? emptySchedule(),
);
const goal = computed(() => state.value.posting_goal ?? DEFAULT_GOAL);
const goalMet = computed(() => slotCount(currentSchedule.value) >= goal.value);

let confirmed: ChannelScheduleState = { ...props.schedule };
let queue: Promise<void> = Promise.resolve();
let queued = 0;

const send = (
    request: () => Promise<ChannelScheduleState>,
    optimistic?: ChannelScheduleState,
): Promise<void> => {
    if (optimistic) {
        state.value = optimistic;
    }

    queued += 1;

    const run = async (): Promise<void> => {
        try {
            const response = await request();

            if (!response) {
                throw new Error('invalid');
            }

            confirmed = response;

            if (queued === 1) {
                state.value = response;
            }
        } catch {
            toast.error(trans('channels.settings_page.save_failed'));

            if (queued === 1) {
                state.value = confirmed;
            }
        } finally {
            queued -= 1;
        }
    };

    queue = queue.then(run);

    return queue;
};

const save = (next: ChannelScheduleState): Promise<void> =>
    send(
        () =>
            http
                .transform(() => ({
                    timezone: next.timezone,
                    posting_goal: next.posting_goal,
                    posting_schedule: next.posting_schedule,
                }))
                .put(update.url(props.channel.id)),
        next,
    );

const pendingTimezone = ref<string | null>(null);

const timezone = computed({
    get: () => state.value.timezone,
    set: (value: string) => {
        if (value !== state.value.timezone) {
            pendingTimezone.value = value;
        }
    },
});

const timezoneConfirmOpen = computed({
    get: () => pendingTimezone.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pendingTimezone.value = null;
        }
    },
});

const closeTimezoneConfirmDialog = (): void => {
    timezoneConfirmOpen.value = false;
};

const confirmTimezone = (): void => {
    const value = pendingTimezone.value;
    pendingTimezone.value = null;

    if (value) {
        void save({ ...state.value, timezone: value });
    }
};

const stepGoal = (delta: number): void => {
    const next = Math.min(MAX_GOAL, Math.max(1, goal.value + delta));
    void save({ ...state.value, posting_goal: next });
};

const changeSchedule = (next: PostingSchedule): void => {
    void save({ ...state.value, posting_schedule: next });
};

type PendingGenerate =
    | { kind: 'goal' }
    | { kind: 'recommended' }
    | { kind: 'copy'; from: string };

const pending = ref<PendingGenerate | null>(null);

const requestGenerate = (action: PendingGenerate): void => {
    pending.value = action;
};

const confirmOpen = computed({
    get: () => pending.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pending.value = null;
        }
    },
});

const closeConfirmDialog = (): void => {
    confirmOpen.value = false;
};

const runPending = (): void => {
    const action = pending.value;
    pending.value = null;

    if (!action) {
        return;
    }

    if (action.kind === 'copy') {
        void send(() =>
            http
                .transform(() => ({ from: action.from }))
                .post(copy.url(props.channel.id)),
        );

        return;
    }

    void send(() =>
        http
            .transform(() => ({ mode: action.kind, goal: goal.value }))
            .post(generate.url(props.channel.id)),
    );
};

const clearOpen = ref(false);

const openClearDialog = (): void => {
    clearOpen.value = true;
};

const closeClearDialog = (): void => {
    clearOpen.value = false;
};

const clearAll = (): void => {
    clearOpen.value = false;
    changeSchedule(cleared(currentSchedule.value));
};

const addTarget = ref<string>('every_day');
const addTargets = computed(() => [
    ...(['every_day', 'weekdays', 'weekends'] as const).map((target) => ({
        value: target,
        labelKey: `channels.settings_page.targets.${target}`,
        label: '',
    })),
    ...orderedWeekdays().map((day) => ({
        value: String(day),
        labelKey: null,
        label: weekdayName(day),
    })),
]);
const selectedTarget = computed(() =>
    addTargets.value.find((option) => option.value === addTarget.value),
);
const currentTime = () => dayjs().tz(state.value.timezone);
const addHour = ref(String(currentTime().hour()).padStart(2, '0'));
const addMinute = ref(String(currentTime().minute()).padStart(2, '0'));

watch(
    () => state.value.timezone,
    () => {
        addHour.value = String(currentTime().hour()).padStart(2, '0');
        addMinute.value = String(currentTime().minute()).padStart(2, '0');
    },
);

const addTriggerClass =
    'h-10 gap-2 rounded-lg border-border-strong data-[size=default]:h-10 bg-transparent px-4 font-medium transition-control hover:bg-accent data-[state=open]:bg-accent [&_svg]:opacity-100';

const hours = Array.from({ length: 24 }, (_, hour) =>
    String(hour).padStart(2, '0'),
);
const minutes = Array.from({ length: 60 }, (_, minute) =>
    String(minute).padStart(2, '0'),
);

const addDays = computed(() =>
    targetDays(
        /^\d$/.test(addTarget.value)
            ? Number(addTarget.value)
            : (addTarget.value as DayTarget),
    ),
);
const canAdd = computed(() => canAddTo(currentSchedule.value, addDays.value));

const weekdayName = (day: number): string =>
    dayjs().locale(activeLocale.value.toLowerCase()).day(day).format('dddd');

const addSlot = (): void => {
    const time = `${addHour.value}:${addMinute.value}`;
    const next = addDays.value.reduce(
        (schedule, day) => withTime(schedule, day, time),
        currentSchedule.value,
    );
    changeSchedule(next);
};
</script>

<template>
    <Head :title="channel.display_name || channel.username" />

    <AppLayout full-width>
        <div
            class="flex flex-col px-4 pt-6 pb-18 md:px-8"
            data-testid="channel-settings-page"
        >
            <div class="flex min-h-12 items-center gap-2">
                <Button
                    as-child
                    variant="ghost"
                    size="icon"
                    class="shrink-0"
                >
                    <Link
                        :href="channelsRoute.url()"
                        :aria-label="$t('channels.settings_page.back')"
                        data-testid="channel-settings-back"
                    >
                        <IconArrowLeft
                            class="size-4 text-muted-foreground rtl:rotate-180"
                        />
                    </Link>
                </Button>

                <div class="flex min-w-0 items-center gap-4">
                    <ChannelAvatar
                        :status="channel.status"
                        :account-id="channel.id"
                        :platform="channel.platform"
                        :src="channel.avatar_url"
                        :name="channel.display_name || channel.username"
                        :size="44"
                    />
                    <div class="min-w-0">
                        <h1
                            class="truncate font-heading text-xl leading-tight font-medium text-foreground"
                        >
                            {{ channel.display_name || channel.username }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('channels.settings_page.subtitle') }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-4 border-t border-border-strong pt-6">
                <div class="flex flex-col gap-8 md:p-4">
                    <section
                        class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                    >
                        <div class="min-w-0">
                            <h2
                                class="text-base leading-5 font-emphasis text-foreground"
                            >
                                {{ $t('channels.settings_page.timezone_title') }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    $t(
                                        'channels.settings_page.timezone_description',
                                    )
                                }}
                            </p>
                        </div>
                        <div class="min-w-0 shrink-0">
                            <TimezoneSelect
                                v-model="timezone"
                                :options="timezones"
                                testid="channel-timezone"
                                compact
                            />
                        </div>
                    </section>

                    <hr class="border-border" />

                    <section
                        class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                    >
                        <div class="min-w-0">
                            <h2
                                class="text-base leading-5 font-emphasis text-foreground"
                            >
                                {{ $t('channels.settings_page.goal_title') }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    $t('channels.settings_page.goal_description')
                                }}
                            </p>
                        </div>
                        <div
                            class="flex h-8 shrink-0 items-center self-start overflow-hidden rounded-md border border-border-strong bg-card md:self-auto"
                        >
                            <button
                                type="button"
                                class="inline-flex h-full w-8 cursor-pointer items-center justify-center text-foreground transition-control hover:bg-accent disabled:cursor-not-allowed disabled:text-subtle-foreground disabled:hover:bg-transparent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                :disabled="goal <= 1"
                                :aria-label="
                                    $t('channels.settings_page.goal_decrease')
                                "
                                data-testid="channel-goal-decrease"
                                @click="stepGoal(-1)"
                            >
                                <IconMinus class="size-4" />
                            </button>
                            <span
                                class="inline-flex min-w-[39px] justify-center px-1 text-sm text-foreground tabular-nums"
                                data-testid="channel-goal-value"
                            >
                                {{ goal }}
                            </span>
                            <button
                                type="button"
                                class="inline-flex h-full w-8 cursor-pointer items-center justify-center text-foreground transition-control hover:bg-accent disabled:cursor-not-allowed disabled:text-subtle-foreground disabled:hover:bg-transparent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                :disabled="goal >= MAX_GOAL"
                                :aria-label="
                                    $t('channels.settings_page.goal_increase')
                                "
                                data-testid="channel-goal-increase"
                                @click="stepGoal(1)"
                            >
                                <IconPlus class="size-4" />
                            </button>
                        </div>
                    </section>

                    <hr class="border-border" />

                    <section class="flex flex-col gap-6">
                        <div
                            class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                        >
                            <div class="min-w-0 md:max-w-[707px]">
                                <h2
                                    class="text-base leading-5 font-emphasis text-foreground"
                                >
                                    {{ $t('channels.settings_page.slots_title') }}
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        $t(
                                            'channels.settings_page.slots_description',
                                        )
                                    }}
                                </p>
                            </div>

                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        class="shrink-0 self-start data-[state=open]:bg-accent md:self-auto"
                                        data-testid="schedule-generate"
                                    >
                                        {{
                                            $t('channels.settings_page.generate')
                                        }}
                                        <IconChevronDown
                                            class="size-4 text-muted-foreground"
                                        />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    class="w-[220px] px-2"
                                >
                                    <TooltipProvider :delay-duration="100">
                                        <Tooltip :disabled="!goalMet">
                                            <TooltipTrigger as-child>
                                                <div
                                                    data-testid="schedule-generate-goal-hint"
                                                >
                                                    <DropdownMenuItem
                                                        :disabled="goalMet"
                                                        class="ps-3"
                                                        data-testid="schedule-generate-goal"
                                                        @select="requestGenerate({ kind: 'goal' })"
                                                    >
                                                        {{
                                                            $t(
                                                                'channels.settings_page.generate_goal',
                                                            )
                                                        }}
                                                    </DropdownMenuItem>
                                                </div>
                                            </TooltipTrigger>
                                            <TooltipContent
                                                side="top"
                                                data-testid="schedule-generate-goal-tooltip"
                                            >
                                                {{
                                                    $t(
                                                        'channels.settings_page.generate_goal_disabled',
                                                    )
                                                }}
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                    <DropdownMenuItem
                                        class="ps-3"
                                        data-testid="schedule-generate-recommended"
                                        @select="requestGenerate({ kind: 'recommended' })"
                                    >
                                        {{
                                            $t(
                                                'channels.settings_page.generate_recommended',
                                            )
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuSub>
                                        <DropdownMenuSubTrigger
                                            :disabled="otherChannels.length === 0"
                                            class="ps-3"
                                            data-testid="schedule-generate-copy"
                                        >
                                            {{
                                                $t(
                                                    'channels.settings_page.generate_copy',
                                                )
                                            }}
                                        </DropdownMenuSubTrigger>
                                        <DropdownMenuPortal>
                                            <DropdownMenuSubContent
                                                class="w-[220px] px-2"
                                            >
                                                <DropdownMenuItem
                                                    v-for="other in otherChannels"
                                                    :key="other.id"
                                                    class="gap-3 ps-3"
                                                    :data-testid="`schedule-generate-copy-${other.id}`"
                                                    @select="requestGenerate({ kind: 'copy', from: other.id })"
                                                >
                                                    <ChannelAvatar
                                                        :platform="other.platform"
                                                        :src="other.avatar_url"
                                                        :name="
                                                            other.display_name ||
                                                            other.username
                                                        "
                                                        ring="popover"
                                                    />
                                                    <span
                                                        class="truncate leading-[21px]"
                                                    >
                                                        {{
                                                            other.display_name ||
                                                            other.username
                                                        }}
                                                    </span>
                                                </DropdownMenuItem>
                                            </DropdownMenuSubContent>
                                        </DropdownMenuPortal>
                                    </DropdownMenuSub>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>

                        <p
                            v-if="state.posting_schedule === null"
                            class="rounded-lg border border-dashed border-border-strong px-4 py-6 text-center text-sm text-muted-foreground"
                            data-testid="schedule-empty"
                        >
                            {{ $t('channels.settings_page.empty') }}
                        </p>

                        <PostingScheduleGrid
                            :schedule="currentSchedule"
                            @change="changeSchedule"
                        />

                        <div
                            class="flex flex-wrap items-center gap-2 text-sm font-medium"
                        >
                            <span>{{
                                $t('channels.settings_page.add_prefix')
                            }}</span>
                            <Select v-model="addTarget">
                                <SelectTrigger
                                    :class="addTriggerClass"
                                    :aria-label="
                                        $t('channels.settings_page.add_prefix')
                                    "
                                    data-testid="schedule-add-target"
                                >
                                    <SelectValue>{{
                                        selectedTarget?.labelKey
                                            ? $t(selectedTarget.labelKey)
                                            : selectedTarget?.label
                                    }}</SelectValue>
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="target in addTargets"
                                        :key="target.value"
                                        :value="target.value"
                                        :data-testid="`schedule-add-target-option-${target.value}`"
                                    >
                                        {{
                                            target.labelKey
                                                ? $t(target.labelKey)
                                                : target.label
                                        }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <span>{{ $t('channels.settings_page.add_at') }}</span>
                            <Select v-model="addHour">
                                <SelectTrigger
                                    :class="[addTriggerClass, 'tabular-nums']"
                                    :aria-label="
                                        $t('channels.settings_page.add_at')
                                    "
                                    data-testid="schedule-add-hour"
                                >
                                    <SelectValue>{{
                                        date.formatHourOption(addHour)
                                    }}</SelectValue>
                                </SelectTrigger>
                                <SelectContent class="max-h-[359px]">
                                    <SelectItem
                                        v-for="hour in hours"
                                        :key="hour"
                                        :value="hour"
                                        :data-testid="`schedule-add-hour-option-${hour}`"
                                    >
                                        {{ date.formatHourOption(hour) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Select v-model="addMinute">
                                <SelectTrigger
                                    :class="[addTriggerClass, 'tabular-nums']"
                                    :aria-label="
                                        $t('channels.settings_page.add_at')
                                    "
                                    data-testid="schedule-add-minute"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent class="max-h-[359px]">
                                    <SelectItem
                                        v-for="minute in minutes"
                                        :key="minute"
                                        :value="minute"
                                        :data-testid="`schedule-add-minute-option-${minute}`"
                                    >
                                        {{ minute }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                v-if="canAdd"
                                type="button"
                                size="lg"
                                data-testid="schedule-add-submit"
                                @click="addSlot"
                            >
                                {{ $t('channels.settings_page.add_button') }}
                            </Button>
                            <span
                                v-else
                                class="font-normal text-muted-foreground"
                                data-testid="schedule-add-limit"
                            >
                                {{
                                    $t('channels.settings_page.add_limit', {
                                        count: String(MAX_TIMES_PER_DAY),
                                    })
                                }}
                            </span>

                            <Button
                                type="button"
                                variant="ghost"
                                size="lg"
                                class="ms-auto text-destructive-text hover:text-destructive-text"
                                data-testid="schedule-clear"
                                @click="openClearDialog"
                            >
                                <IconTrash class="size-4 text-destructive-text" />
                                {{ $t('channels.settings_page.clear_all') }}
                            </Button>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <Dialog v-model:open="confirmOpen">
            <DialogContent :show-close-button="false">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('channels.settings_page.generate_confirm_title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'channels.settings_page.generate_confirm_description',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="closeConfirmDialog"
                    >
                        {{ $t('channels.settings_page.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="schedule-generate-confirm"
                        @click="runPending"
                    >
                        {{ $t('channels.settings_page.confirm') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="clearOpen">
            <DialogContent :show-close-button="false">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('channels.settings_page.clear_confirm_title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'channels.settings_page.clear_confirm_description',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="closeClearDialog"
                    >
                        {{ $t('channels.settings_page.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="schedule-clear-confirm"
                        @click="clearAll"
                    >
                        {{ $t('channels.settings_page.clear_all') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="timezoneConfirmOpen">
            <DialogContent
                :show-close-button="false"
                data-testid="channel-timezone-confirm-dialog"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{
                            $t('channels.settings_page.timezone_confirm_title')
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'channels.settings_page.timezone_confirm_description',
                                { timezone: pendingTimezone ?? '' },
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="channel-timezone-confirm-cancel"
                        @click="closeTimezoneConfirmDialog"
                    >
                        {{ $t('channels.settings_page.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="channel-timezone-confirm-submit"
                        @click="confirmTimezone"
                    >
                        {{ $t('channels.settings_page.timezone_confirm') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
