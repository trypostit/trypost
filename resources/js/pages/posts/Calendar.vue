<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    IconChevronDown,
    IconLayoutSidebarRightCollapse,
    IconLayoutSidebarRightExpand,
    IconPlus,
} from '@tabler/icons-vue';
import { useSwipe, type UseSwipeDirection } from '@vueuse/core';
import {
    computed,
    onMounted,
    onUnmounted,
    provide,
    ref,
    useTemplateRef,
    watch,
} from 'vue';

import { destroy as destroyPost } from '@/actions/App/Http/Controllers/App/PostController';
import AppHeaderActions from '@/components/AppHeaderActions.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import PostChannelFilter from '@/components/posts/PostChannelFilter.vue';
import ScheduleViewSwitch from '@/components/posts/ScheduleViewSwitch.vue';
import CalendarPeriodPicker from '@/components/publish/CalendarPeriodPicker.vue';
import CalendarPostChip from '@/components/publish/CalendarPostChip.vue';
import CalendarSlotChip from '@/components/publish/CalendarSlotChip.vue';
import CalendarTimeGrid from '@/components/publish/CalendarTimeGrid.vue';
import CalendarUndatedPanel from '@/components/publish/CalendarUndatedPanel.vue';
import PublishFilterMenu from '@/components/publish/PublishFilterMenu.vue';
import PublishHeader from '@/components/publish/PublishHeader.vue';
import { Button } from '@/components/ui/button';
import { useAtLeastBreakpoint } from '@/composables/useBreakpoint';
import { useCollapseWhenTruncated } from '@/composables/useCollapseWhenTruncated';
import { useDisplayTimezone } from '@/composables/useDisplayTimezone';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import {
    deletePostCardKey,
    postCardLabelsKey,
} from '@/composables/usePostCardActions';
import { useShowPostingSlots } from '@/composables/useShowPostingSlots';
import { provideViewTimezone } from '@/composables/useViewTimezone';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import AppLayout from '@/layouts/AppLayout.vue';
import { calendarItems, type CalendarItem } from '@/lib/calendarItems';
import { calendar } from '@/routes/app';
import {
    calendar as channelCalendar,
    grid,
    publish,
    settings,
} from '@/routes/app/channels';
import { index as postsIndex } from '@/routes/app/posts';
import type { User } from '@/types';
import type { TimezoneOption } from '@/types/posting-schedule';
import type {
    CalendarPost,
    CalendarSlot,
    CalendarStatus,
    CalendarView,
    PostCard,
    PostCardLabel,
    PublishChannel,
    PublishScope,
    PublishSocialAccount,
    ScrollUndatedDrafts,
} from '@/types/publish';

interface Props {
    scope: PublishScope;
    channel: PublishChannel | null;
    posts: Record<string, CalendarPost[]>;
    currentDay: string;
    currentWeekStart: string;
    currentMonth: string;
    view: CalendarView;
    displayTimezone: string;
    timezones: TimezoneOption[];
    labels: PostCardLabel[];
    filters: {
        labels: string[];
        untagged: boolean;
        channels: string[];
        status: CalendarStatus;
    };
    filterAccounts: PublishSocialAccount[];
    undatedDrafts?: ScrollUndatedDrafts;
    slots?: Record<string, CalendarSlot[]>;
}

const props = defineProps<Props>();
const page = usePage();
const { canCreatePost, canManageAccounts } = useWorkspaceAbilities();
const { showSlots, setShowSlots } = useShowPostingSlots();

const VIEWS: readonly CalendarView[] = ['days', 'week', 'month'];
const DAYS_SPAN = 3;
const MONTH_CHIPS = 3;
const RELOAD_PROPS = [
    'posts',
    'currentDay',
    'currentWeekStart',
    'currentMonth',
    'view',
    'displayTimezone',
    'filters',
    'slots',
];

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

provide(deletePostCardKey, (post: PostCard) => {
    deleteModal.value?.open({ url: destroyPost.url(post.id) });
});

provide(
    postCardLabelsKey,
    computed(() => props.labels),
);

const now = ref(dayjs());
let clock: ReturnType<typeof setInterval> | undefined;

onUnmounted(() => {
    clearInterval(clock);
});

const userTimezone = computed(
    () => (page.props.auth.user as User).timezone || props.displayTimezone,
);

const { timezone, setTimezone } = useDisplayTimezone(
    props.displayTimezone,
    props.timezones.map((option) => option.value),
    undefined,
    RELOAD_PROPS,
);

provideViewTimezone(timezone);

watch(
    () => props.displayTimezone,
    (value) => {
        timezone.value = value;
    },
);

const selectedLabelIds = ref<string[]>(props.filters.labels ?? []);
const selectedUntagged = ref<boolean>(props.filters.untagged ?? false);
const selectedChannelIds = ref<string[]>(props.filters.channels ?? []);
const selectedStatus = ref<CalendarStatus>(props.filters.status ?? 'all');
const undatedOpen = ref(props.undatedDrafts !== undefined);

const filterQuery = (): Record<string, string | string[] | undefined> => ({
    labels: selectedLabelIds.value.length ? selectedLabelIds.value : undefined,
    untagged: selectedUntagged.value ? '1' : undefined,
    channels:
        props.scope === 'all' && selectedChannelIds.value.length
            ? selectedChannelIds.value
            : undefined,
    tz: timezone.value !== userTimezone.value ? timezone.value : undefined,
});

const calendarUrl = (
    view: CalendarView,
    dateQuery: Record<string, string> = {},
): string => {
    const query = {
        ...dateQuery,
        ...filterQuery(),
        status:
            selectedStatus.value !== 'all' ? selectedStatus.value : undefined,
        undated: undatedOpen.value ? '1' : undefined,
        slots: showSlots.value ? '1' : undefined,
    };

    return props.channel
        ? channelCalendar.url({ account: props.channel.id, view }, { query })
        : calendar.url({ view }, { query });
};

const listHref = computed((): string => {
    const query = filterQuery();

    return props.channel
        ? publish.url(props.channel.id, {
              query: {
                  labels: query.labels,
                  untagged: query.untagged,
                  tz: query.tz,
              },
          })
        : postsIndex.url({ query });
});

const visit = (url: string): void => {
    router.get(
        url,
        {},
        { preserveState: true, preserveScroll: true, only: RELOAD_PROPS },
    );
};

const reload = (only: string[], reset: string[] = []): void => {
    router.get(
        calendarUrl(props.view, currentDateQuery()),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
            reset,
        },
    );
};

watch(
    [selectedLabelIds, selectedUntagged, selectedChannelIds, selectedStatus],
    () => reload([...RELOAD_PROPS, 'undatedDrafts'], ['undatedDrafts']),
    { deep: true },
);

const toggleUndated = (): void => {
    undatedOpen.value = !undatedOpen.value;
    reload(['undatedDrafts'], ['undatedDrafts']);
};

const closeUndated = (): void => {
    if (undatedOpen.value) {
        toggleUndated();
    }
};

const toggleSlots = (value: boolean): void => {
    setShowSlots(value);
    reload(['slots']);
};

onMounted(() => {
    clock = setInterval(() => {
        now.value = dayjs();
    }, 60_000);

    if (showSlots.value && props.slots === undefined) {
        router.get(
            calendarUrl(props.view, currentDateQuery()),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                only: ['slots'],
            },
        );
    }
});

/**
 * Every date on this screen goes through here: `dayjs.locale()` is global and
 * not reactive, so a computed built on a bare `dayjs()` keeps the previous
 * language's month and day names after a switch.
 */
const localized = (value?: dayjs.ConfigType) =>
    dayjs(value).locale(activeLocale.value.toLowerCase());

const todayKey = computed(() =>
    now.value.tz(timezone.value).format('YYYY-MM-DD'),
);

const isDesktop = useAtLeastBreakpoint('md');

const toolbar = useTemplateRef<HTMLElement>('toolbar');
const periodPicker =
    useTemplateRef<InstanceType<typeof CalendarPeriodPicker>>('periodPicker');
const { collapsed: filtersCollapsed, expand: showAllFilters } =
    useCollapseWhenTruncated(
        toolbar,
        () => periodPicker.value?.missingTitleWidth() ?? 0,
    );
const compactFilters = computed(
    () => !isDesktop.value || filtersCollapsed.value,
);

const weekdayNames = computed(() => {
    const start = localized().startOf('week');

    return Array.from({ length: 7 }, (_, index) =>
        start.add(index, 'day').format(isDesktop.value ? 'dddd' : 'ddd'),
    );
});

const periodStart = computed(() =>
    localized(props.view === 'days' ? props.currentDay : props.currentWeekStart),
);

const periodLength = computed(() => (props.view === 'days' ? DAYS_SPAN : 7));
const monthDate = computed(() => localized(props.currentMonth));

const periodDays = computed(() =>
    Array.from({ length: periodLength.value }, (_, index) =>
        periodStart.value.add(index, 'day'),
    ),
);

const weekHeaderTitle = computed(() => {
    const start = periodStart.value;
    const end = periodStart.value.add(periodLength.value - 1, 'day');

    if (start.isSame(end, 'month')) {
        return `${start.format('D')}–${end.format('D MMMM YYYY')}`;
    }

    if (start.isSame(end, 'year')) {
        return `${start.format('D MMM')} – ${end.format('D MMMM YYYY')}`;
    }

    return `${start.format('ll')} – ${end.format('ll')}`;
});

const calendarWeeks = computed(() => {
    const start = monthDate.value.startOf('month').startOf('week');
    const end = monthDate.value.endOf('month').endOf('week');
    const weeks: dayjs.Dayjs[][] = [];
    let current = start;

    while (!current.isAfter(end, 'day')) {
        if (current.day() === start.day()) {
            weeks.push([]);
        }

        weeks[weeks.length - 1].push(current);
        current = current.add(1, 'day');
    }

    return weeks;
});

const headerTitle = computed(() =>
    props.view === 'month'
        ? monthDate.value.format('MMMM YYYY')
        : weekHeaderTitle.value,
);

watch(headerTitle, showAllFilters);

const currentDateQuery = (
    view: CalendarView = props.view,
): Record<string, string> =>
    view === 'month'
        ? { month: props.currentMonth }
        : view === 'days'
          ? { day: props.currentDay }
          : { week: props.currentWeekStart };

const navigate = (direction: number): void => {
    if (props.view === 'month') {
        visit(
            calendarUrl('month', {
                month: monthDate.value
                    .add(direction, 'month')
                    .format('YYYY-MM-DD'),
            }),
        );
    } else {
        const start = periodStart.value
            .add(direction * periodLength.value, 'day')
            .format('YYYY-MM-DD');

        visit(
            calendarUrl(
                props.view,
                props.view === 'days' ? { day: start } : { week: start },
            ),
        );
    }
};

const switchView = (view: CalendarView): void => visit(calendarUrl(view));

const dayKey = (day: dayjs.Dayjs): string => day.format('YYYY-MM-DD');

const isToday = (day: dayjs.Dayjs): boolean => dayKey(day) === todayKey.value;

const isPast = (day: dayjs.Dayjs): boolean => dayKey(day) < todayKey.value;

const isCurrentMonth = (day: dayjs.Dayjs): boolean =>
    day.month() === monthDate.value.month();

const visibleSlots = computed<Record<string, CalendarSlot[]>>(() =>
    showSlots.value ? (props.slots ?? {}) : {},
);

const slotChannels = computed<Record<string, PublishSocialAccount>>(() =>
    Object.fromEntries(
        (props.channel ? [props.channel] : props.filterAccounts).map(
            (account) => [account.id, account],
        ),
    ),
);

const itemsFor = (day: dayjs.Dayjs): CalendarItem[] =>
    calendarItems(
        props.posts[dayKey(day)] ?? [],
        visibleSlots.value[dayKey(day)] ?? [],
    );

const composerAccounts = (): string[] =>
    props.channel ? [props.channel.id] : [];

const newPost = (): void =>
    openPostComposer({ socialAccountIds: composerAccounts() });

const composeAt = (utcIso: string): void =>
    openPostComposer({
        date: date.formatUtcForDateTimeLocalInput(utcIso),
        socialAccountIds: composerAccounts(),
    });

const composeOn = (day: dayjs.Dayjs): void =>
    composeAt(dayjs.tz(`${dayKey(day)}T09:00`, timezone.value).utc().format());

const expandedDays = ref<string[]>([]);

const toggleDay = (key: string): void => {
    expandedDays.value = expandedDays.value.includes(key)
        ? expandedDays.value.filter((day) => day !== key)
        : [...expandedDays.value, key];
};

const visibleMonthItems = (day: dayjs.Dayjs): CalendarItem[] =>
    expandedDays.value.includes(dayKey(day))
        ? itemsFor(day)
        : itemsFor(day).slice(0, MONTH_CHIPS);

const mobileTitle = computed(() => {
    if (props.view === 'month') {
        return monthDate.value.format('MMMM YYYY');
    }

    const start = periodStart.value;
    const end = periodStart.value.add(periodLength.value - 1, 'day');

    if (start.isSame(end, 'month')) {
        return start.format('MMMM YYYY');
    }

    if (start.isSame(end, 'year')) {
        return `${start.format('MMM')} – ${end.format('MMM YYYY')}`;
    }

    return `${start.format('MMM YYYY')} – ${end.format('MMM YYYY')}`;
});

const dayKeys = computed(() =>
    (props.view === 'month' ? calendarWeeks.value.flat() : periodDays.value).map(
        dayKey,
    ),
);

const periodDayKeys = computed(() =>
    props.view === 'month'
        ? dayKeys.value.filter((key) =>
              key.startsWith(monthDate.value.format('YYYY-MM')),
          )
        : dayKeys.value,
);

const defaultDayKey = (): string =>
    periodDayKeys.value.includes(todayKey.value)
        ? todayKey.value
        : periodDayKeys.value[0];

const selectedDayKey = ref(defaultDayKey());
const pendingDayKey = ref<string | null>(null);

watch(dayKeys, (keys, previousKeys) => {
    const pending = pendingDayKey.value;
    pendingDayKey.value = null;

    if (pending && keys.includes(pending)) {
        selectedDayKey.value = pending;

        return;
    }

    if (keys.includes(selectedDayKey.value)) {
        return;
    }

    const offset = previousKeys.indexOf(selectedDayKey.value);

    selectedDayKey.value =
        props.view === 'week' &&
        previousKeys.length === 7 &&
        offset >= 0 &&
        !keys.includes(todayKey.value)
            ? keys[offset]
            : defaultDayKey();
});

const isRtl = (): boolean => document.documentElement.dir === 'rtl';

const swipeStep = (direction: UseSwipeDirection): number => {
    if (direction !== 'left' && direction !== 'right') {
        return 0;
    }

    return (direction === 'left') !== isRtl() ? 1 : -1;
};

const periodSwipeTarget = ref<HTMLElement | null>(null);

useSwipe(periodSwipeTarget, {
    threshold: 48,
    onSwipeEnd: (_event, direction) => {
        const step = swipeStep(direction);

        if (step !== 0) {
            navigate(step);
        }
    },
});

const goToToday = (): void => {
    if (periodDayKeys.value.includes(todayKey.value)) {
        selectedDayKey.value = todayKey.value;
    } else {
        pendingDayKey.value = todayKey.value;
    }

    visit(calendarUrl(props.view));
};

const goToDay = (key: string): void => {
    if (periodDayKeys.value.includes(key)) {
        selectedDayKey.value = key;

        return;
    }

    const day = localized(key);
    pendingDayKey.value = key;
    visit(
        calendarUrl(
            props.view,
            {
                month: { month: day.startOf('month').format('YYYY-MM-DD') },
                days: { day: key },
                week: { week: day.startOf('week').format('YYYY-MM-DD') },
            }[props.view],
        ),
    );
};
</script>

<template>
    <Head :title="$t('calendar.title')" />

    <AppLayout full-width>
        <template #header>
            <PublishHeader :channel="channel" />
        </template>

        <template #header-actions>
            <div class="flex items-center gap-2">
                <Button
                    v-if="canCreatePost"
                    variant="outline"
                    class="max-sm:w-8 max-sm:px-0"
                    :aria-label="$t('calendar.new_post')"
                    data-testid="calendar-new-post"
                    @click="newPost"
                >
                    <IconPlus class="size-4" />
                    <span class="max-sm:sr-only">{{
                        $t('calendar.new_post')
                    }}</span>
                </Button>
            </div>
        </template>

        <div class="flex min-h-0 flex-1 flex-col">
            <div
                class="mx-4 mt-2 flex h-12 shrink-0 items-center justify-between gap-2 md:mx-8"
                ref="toolbar"
                data-testid="calendar-toolbar"
            >
                <CalendarPeriodPicker
                    ref="periodPicker"
                    :view="view"
                    :views="VIEWS"
                    :title="isDesktop ? headerTitle : mobileTitle"
                    :selected-day-key="selectedDayKey"
                    @navigate="navigate"
                    @today="goToToday"
                    @change-view="switchView"
                    @pick-day="goToDay"
                />

                <div
                    class="flex shrink-0 items-center gap-1 md:ms-auto md:gap-0"
                    data-testid="calendar-filters"
                >
                    <PostChannelFilter
                        v-if="scope === 'all' && filterAccounts.length"
                        v-model="selectedChannelIds"
                        :channels="filterAccounts"
                    />
                    <div class="shrink-0 max-md:hidden">
                        <LabelFilter
                            v-model="selectedLabelIds"
                            v-model:untagged="selectedUntagged"
                            :labels="labels"
                        />
                    </div>
                    <PublishFilterMenu
                        v-model:status="selectedStatus"
                        :timezone="timezone"
                        test-id="calendar"
                        :compact="compactFilters"
                        :timezones="timezones"
                        :show-slots="showSlots"
                        :manage-slots-href="
                            channel && canManageAccounts
                                ? settings.url(channel.id)
                                : null
                        "
                        @update:timezone="setTimezone"
                        @update:show-slots="toggleSlots"
                    />
                    <AppHeaderActions>
                        <ScheduleViewSwitch
                            active-view="calendar"
                            :list-href="listHref"
                            :calendar-href="calendarUrl('month')"
                            :grid-href="
                                channel?.has_grid
                                    ? grid.url(channel.id)
                                    : undefined
                            "
                        />
                    </AppHeaderActions>
                    <Button
                        variant="ghost"
                        class="shrink-0 max-xl:size-8 max-xl:px-0 max-sm:border max-sm:border-border-strong"
                        :class="{ 'bg-accent': undatedOpen }"
                        :aria-pressed="undatedOpen"
                        data-testid="calendar-no-date"
                        @click="toggleUndated"
                    >
                        <IconLayoutSidebarRightCollapse
                            v-if="undatedOpen"
                            class="size-4 text-muted-foreground"
                            data-testid="calendar-no-date-icon-open"
                        />
                        <IconLayoutSidebarRightExpand
                            v-else
                            class="size-4 text-muted-foreground"
                            data-testid="calendar-no-date-icon-closed"
                        />
                        <span class="max-xl:sr-only">{{
                            $t('calendar.no_date')
                        }}</span>
                    </Button>
                </div>
            </div>

            <div class="mx-4 flex min-h-0 flex-1 gap-4 md:mx-8">
                <div
                    ref="periodSwipeTarget"
                    class="mb-4 flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-border-strong md:mb-6"
                    :class="{ 'max-md:hidden': undatedOpen }"
                    data-testid="calendar-grid"
                >
                    <CalendarTimeGrid
                        v-if="view !== 'month'"
                        :days="periodDays"
                        :posts="posts"
                        :slots="visibleSlots"
                        :channels="slotChannels"
                        :timezone="timezone"
                        :now="now"
                        :can-create-post="canCreatePost"
                        @compose="composeAt"
                    />

                    <div
                        v-else
                        class="flex flex-1 flex-col overflow-y-auto overscroll-contain"
                        data-testid="calendar-month-grid"
                    >
                        <div
                            class="sticky top-0 z-10 grid grid-cols-7 border-b border-border-strong bg-card"
                        >
                            <div
                                v-for="(name, index) in weekdayNames"
                                :key="name"
                                class="border-border-strong p-1.5 text-center text-sm font-medium text-muted-foreground capitalize md:p-2.5"
                                :class="{ 'border-l': index > 0 }"
                            >
                                {{ name }}
                            </div>
                        </div>

                        <div class="flex flex-1 flex-col">
                            <div
                                v-for="(week, weekIndex) in calendarWeeks"
                                :key="weekIndex"
                                class="grid min-h-24 flex-1 grid-cols-7 border-border-strong md:min-h-[208px]"
                                :class="{ 'border-t': weekIndex > 0 }"
                            >
                                <div
                                    v-for="(day, index) in week"
                                    :key="dayKey(day)"
                                    class="group flex min-w-0 flex-col gap-1 border-border-strong p-1 md:p-2"
                                    :class="{
                                        'border-l': index > 0,
                                        'bg-accent': isPast(day),
                                    }"
                                    :data-testid="`calendar-day-${dayKey(day)}`"
                                >
                                    <div class="flex items-center justify-between">
                                        <span
                                            class="inline-flex size-6 items-center justify-center rounded-full text-sm font-medium"
                                            :class="{
                                                'bg-primary text-primary-foreground':
                                                    isToday(day),
                                                'text-subtle-foreground':
                                                    !isToday(day) &&
                                                    !isCurrentMonth(day),
                                                'text-muted-foreground':
                                                    !isToday(day) &&
                                                    isCurrentMonth(day),
                                            }"
                                        >
                                            {{ day.format('D') }}
                                        </span>
                                        <button
                                            v-if="canCreatePost && !isPast(day)"
                                            type="button"
                                            :aria-label="$t('calendar.new_post')"
                                            class="flex size-6 items-center justify-center rounded-md border border-border-strong bg-card text-muted-foreground opacity-0 transition-opacity duration-100 ease-in-out group-hover:opacity-100 hover:text-foreground focus-visible:opacity-100 max-md:hidden [@media(hover:none)]:opacity-100"
                                            :data-testid="`calendar-add-${dayKey(day)}`"
                                            @click="composeOn(day)"
                                        >
                                            <IconPlus class="size-3.5" />
                                        </button>
                                    </div>

                                    <div class="flex flex-col gap-1">
                                        <template
                                            v-for="item in visibleMonthItems(day)"
                                            :key="item.key"
                                        >
                                            <CalendarPostChip
                                                v-if="item.post"
                                                :post="item.post"
                                                :timezone="timezone"
                                            />
                                            <CalendarSlotChip
                                                v-else-if="item.slot"
                                                :posting-slot="item.slot"
                                                :channel="
                                                    slotChannels[
                                                        item.slot.channel_id
                                                    ] ?? null
                                                "
                                                :timezone="timezone"
                                                :can-create-post="canCreatePost"
                                            />
                                        </template>
                                        <button
                                            v-if="
                                                itemsFor(day).length > MONTH_CHIPS
                                            "
                                            type="button"
                                            class="inline-flex h-6 items-center gap-1 self-end rounded-md px-2 text-xs font-medium text-foreground transition-control hover:bg-accent"
                                            :aria-expanded="
                                                expandedDays.includes(dayKey(day))
                                            "
                                            :data-testid="`calendar-more-${dayKey(day)}`"
                                            @click="toggleDay(dayKey(day))"
                                        >
                                            <IconChevronDown
                                                class="size-3.5 transition-transform"
                                                :class="{
                                                    'rotate-180':
                                                        expandedDays.includes(
                                                            dayKey(day),
                                                        ),
                                                }"
                                            />
                                            {{
                                                expandedDays.includes(
                                                    dayKey(day),
                                                )
                                                    ? $t('calendar.less')
                                                    : $t('calendar.more', {
                                                          count: String(
                                                              itemsFor(day)
                                                                  .length -
                                                                  MONTH_CHIPS,
                                                          ),
                                                      })
                                            }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <Transition
                    enter-active-class="transition-[width,margin,opacity] duration-200 ease-out motion-reduce:transition-none"
                    leave-active-class="transition-[width,margin,opacity] duration-200 ease-out motion-reduce:transition-none"
                    enter-from-class="opacity-0 md:-ms-4! md:w-0!"
                    leave-to-class="opacity-0 md:-ms-4! md:w-0!"
                >
                    <div
                        v-if="undatedOpen"
                        class="flex min-h-0 shrink-0 overflow-hidden max-md:w-full md:w-[360px]"
                        data-testid="calendar-undated-slide"
                    >
                        <CalendarUndatedPanel
                            :drafts="undatedDrafts?.data ?? null"
                            @close="closeUndated"
                        />
                    </div>
                </Transition>
            </div>
        </div>
    </AppLayout>

    <ConfirmDeleteModal
        ref="deleteModal"
        :title="$t('posts.edit.delete_modal.title')"
        :description="$t('posts.edit.delete_modal.description')"
        :action="$t('posts.edit.delete_modal.action')"
        :cancel="$t('posts.edit.delete_modal.cancel')"
    />
</template>
