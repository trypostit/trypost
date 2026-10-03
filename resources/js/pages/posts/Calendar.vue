<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    IconCheck,
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconDotsVertical,
    IconLayoutSidebarRight,
    IconPlus,
} from '@tabler/icons-vue';
import { computed, onMounted, onUnmounted, provide, ref, watch } from 'vue';

import { destroy as destroyPost } from '@/actions/App/Http/Controllers/App/PostController';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import PostChannelFilter from '@/components/posts/PostChannelFilter.vue';
import ScheduleViewSwitch from '@/components/posts/ScheduleViewSwitch.vue';
import CalendarPostChip from '@/components/publish/CalendarPostChip.vue';
import CalendarSlotChip from '@/components/publish/CalendarSlotChip.vue';
import CalendarStatusFilter from '@/components/publish/CalendarStatusFilter.vue';
import CalendarTimeGrid from '@/components/publish/CalendarTimeGrid.vue';
import CalendarUndatedPanel from '@/components/publish/CalendarUndatedPanel.vue';
import PublishHeader from '@/components/publish/PublishHeader.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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

const VIEWS: readonly CalendarView[] = ['week', 'month'];
const MONTH_CHIPS = 3;
const RELOAD_PROPS = [
    'posts',
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

const weekdayNames = computed(() => {
    const start = localized().startOf('week');

    return Array.from({ length: 7 }, (_, index) =>
        start.add(index, 'day').format('dddd'),
    );
});

const weekStart = computed(() => localized(props.currentWeekStart));
const monthDate = computed(() => localized(props.currentMonth));

const weekDays = computed(() =>
    Array.from({ length: 7 }, (_, index) =>
        weekStart.value.add(index, 'day'),
    ),
);

const weekHeaderTitle = computed(() => {
    const start = weekStart.value;
    const end = weekStart.value.add(6, 'day');

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

const currentDateQuery = (
    view: CalendarView = props.view,
): Record<string, string> =>
    view === 'month'
        ? { month: props.currentMonth }
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
        visit(
            calendarUrl('week', {
                week: weekStart.value
                    .add(direction * 7, 'day')
                    .format('YYYY-MM-DD'),
            }),
        );
    }
};

const goToToday = (): void => visit(calendarUrl(props.view));

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
</script>

<template>
    <Head :title="$t('calendar.title')" />

    <AppLayout full-width>
        <template #header>
            <PublishHeader :channel="channel" />
        </template>

        <template #header-actions>
            <div class="flex items-center gap-2">
                <ScheduleViewSwitch
                    active-view="calendar"
                    :list-href="listHref"
                    :calendar-href="calendarUrl('month')"
                    :grid-href="
                        channel?.has_grid ? grid.url(channel.id) : undefined
                    "
                />
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
                class="mx-4 mt-2 flex shrink-0 flex-col md:mx-8 md:h-12 md:flex-row md:items-center md:gap-4"
                data-testid="calendar-toolbar"
            >
                <div class="flex h-12 min-w-0 items-center gap-4">
                    <div class="flex min-w-0 items-center">
                        <Button
                            variant="ghost"
                            size="icon"
                            class="-ms-2 shrink-0 md:-ms-3"
                            :aria-label="$t('calendar.previous')"
                            data-testid="calendar-previous"
                            @click="navigate(-1)"
                        >
                            <IconChevronLeft class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="shrink-0"
                            :aria-label="$t('calendar.next')"
                            data-testid="calendar-next"
                            @click="navigate(1)"
                        >
                            <IconChevronRight class="size-4" />
                        </Button>
                        <h2
                            class="ms-1 truncate font-heading text-base leading-5 font-medium text-foreground capitalize"
                            data-testid="calendar-title"
                        >
                            {{ headerTitle }}
                        </h2>
                    </div>
                    <Button
                        variant="outline"
                        class="shrink-0"
                        data-testid="calendar-today"
                        @click="goToToday"
                    >
                        {{ $t('calendar.today') }}
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                class="shrink-0 data-[state=open]:bg-accent"
                                data-testid="calendar-view-trigger"
                            >
                                {{ $t(`calendar.${view}`) }}
                                <IconChevronDown
                                    class="size-4 text-muted-foreground"
                                />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuItem
                                v-for="option in VIEWS"
                                :key="option"
                                :data-testid="`calendar-view-${option}`"
                                @click="switchView(option)"
                            >
                                <IconCheck
                                    class="size-4"
                                    :class="view === option ? '' : 'invisible'"
                                />
                                {{ $t(`calendar.${option}`) }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <div
                    class="-mx-1 flex min-w-0 items-center gap-2 overflow-x-auto px-1 pb-2 md:ms-auto md:overflow-visible md:p-0"
                    data-testid="calendar-filters"
                >
                    <PostChannelFilter
                        v-if="scope === 'all' && filterAccounts.length"
                        v-model="selectedChannelIds"
                        :channels="filterAccounts"
                    />
                    <CalendarStatusFilter v-model="selectedStatus" />
                    <LabelFilter
                        v-model="selectedLabelIds"
                        v-model:untagged="selectedUntagged"
                        :labels="labels"
                    />
                    <Button
                        variant="ghost"
                        class="shrink-0"
                        :class="{ 'bg-accent': undatedOpen }"
                        :aria-pressed="undatedOpen"
                        data-testid="calendar-no-date"
                        @click="toggleUndated"
                    >
                        <IconLayoutSidebarRight
                            class="size-4 text-muted-foreground"
                        />
                        {{ $t('calendar.no_date') }}
                    </Button>
                    <span
                        class="h-5 w-px shrink-0 bg-border-strong"
                        aria-hidden="true"
                    />
                    <div
                        class="shrink-0"
                        data-testid="publish-timezone-select"
                        role="group"
                        :aria-label="$t('posts.publish.timezone.label')"
                    >
                        <TimezoneSelect
                            :model-value="timezone"
                            :options="timezones"
                            testid="publish-timezone"
                            variant="ghost"
                            compact
                            @update:model-value="setTimezone"
                        />
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="shrink-0 data-[state=open]:bg-accent"
                                :aria-label="$t('posts.table.actions')"
                                data-testid="calendar-menu"
                            >
                                <IconDotsVertical class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuCheckboxItem
                                :model-value="showSlots"
                                data-testid="calendar-toggle-slots"
                                @update:model-value="toggleSlots"
                            >
                                {{
                                    $t('posts.publish.menu.show_posting_times')
                                }}
                            </DropdownMenuCheckboxItem>
                            <DropdownMenuItem
                                v-if="channel && canManageAccounts"
                                as-child
                            >
                                <Link
                                    :href="settings.url(channel.id)"
                                    data-testid="calendar-manage-slots"
                                >
                                    {{
                                        $t(
                                            'posts.publish.menu.manage_posting_times',
                                        )
                                    }}
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <div class="mx-4 flex min-h-0 flex-1 gap-4 md:mx-8">
                <div
                    class="mb-4 flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-border-strong md:mb-6"
                    :class="{ 'max-md:hidden': undatedOpen }"
                    data-testid="calendar-grid"
                >
                    <CalendarTimeGrid
                        v-if="view !== 'month'"
                        :days="weekDays"
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
                        class="flex-1 overflow-y-auto overscroll-contain"
                        data-testid="calendar-month-grid"
                    >
                        <div
                            class="sticky top-0 z-10 grid grid-cols-7 border-b border-border-strong bg-card"
                        >
                            <div
                                v-for="(name, index) in weekdayNames"
                                :key="name"
                                class="border-border-strong p-2.5 text-center text-sm font-medium text-muted-foreground capitalize"
                                :class="{ 'border-l': index > 0 }"
                            >
                                {{ name }}
                            </div>
                        </div>

                        <div>
                            <div
                                v-for="(week, weekIndex) in calendarWeeks"
                                :key="weekIndex"
                                class="grid min-h-[208px] grid-cols-7 border-border-strong"
                                :class="{ 'border-t': weekIndex > 0 }"
                            >
                                <div
                                    v-for="(day, index) in week"
                                    :key="dayKey(day)"
                                    class="group flex min-w-0 flex-col gap-1 border-border-strong p-2"
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
                                            class="flex size-6 items-center justify-center rounded-md border border-border-strong bg-card text-muted-foreground opacity-0 transition-opacity duration-100 ease-in-out group-hover:opacity-100 hover:text-foreground focus-visible:opacity-100 [@media(hover:none)]:opacity-100"
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

                <CalendarUndatedPanel
                    v-if="undatedOpen"
                    :drafts="undatedDrafts?.data ?? null"
                    @close="closeUndated"
                />
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
