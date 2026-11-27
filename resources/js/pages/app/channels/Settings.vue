<script setup lang="ts">
import { Head, Link, useHttp } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import ChannelPostingGoalSetting from '@/components/channels/ChannelPostingGoalSetting.vue';
import ChannelTimezoneSetting from '@/components/channels/ChannelTimezoneSetting.vue';
import PostingScheduleAddSlot from '@/components/channels/PostingScheduleAddSlot.vue';
import PostingScheduleGenerateMenu from '@/components/channels/PostingScheduleGenerateMenu.vue';
import PostingScheduleGrid from '@/components/channels/PostingScheduleGrid.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    cleared,
    emptySchedule,
    slotCount,
    withTime,
} from '@/lib/postingSchedule';
import { copy, generate, update } from '@/routes/app/channels/posting-schedule';
import { channels as channelsRoute } from '@/routes/app/workspace';
import type {
    ChannelScheduleState,
    OtherChannel,
    PostingSchedule,
    ScheduleGenerateAction,
    TimezoneOption,
} from '@/types/posting-schedule';
import type { ConnectedAccount } from '@/types/social-account';

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

const goal = computed({
    get: () => state.value.posting_goal ?? DEFAULT_GOAL,
    set: (value: number) => {
        void save({ ...state.value, posting_goal: value });
    },
});
const goalMet = computed(() => slotCount(currentSchedule.value) >= goal.value);

const changeTimezone = (timezone: string): void => {
    void save({ ...state.value, timezone });
};

const changeSchedule = (next: PostingSchedule): void => {
    void save({ ...state.value, posting_schedule: next });
};

const generateSchedule = (action: ScheduleGenerateAction): void => {
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

const addSlot = (days: number[], time: string): void => {
    changeSchedule(
        days.reduce(
            (schedule, day) => withTime(schedule, day, time),
            currentSchedule.value,
        ),
    );
};

const clearAll = (): void => {
    changeSchedule(cleared(currentSchedule.value));
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
                        :verified="channel.verified_badge"
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
                    <ChannelTimezoneSetting
                        :timezone="state.timezone"
                        :timezones="timezones"
                        @change="changeTimezone"
                    />

                    <hr class="border-border" />

                    <ChannelPostingGoalSetting v-model="goal" />

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

                            <PostingScheduleGenerateMenu
                                :goal-met="goalMet"
                                :other-channels="otherChannels"
                                @generate="generateSchedule"
                            />
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

                        <PostingScheduleAddSlot
                            :schedule="currentSchedule"
                            :timezone="state.timezone"
                            @add="addSlot"
                            @clear="clearAll"
                        />
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
