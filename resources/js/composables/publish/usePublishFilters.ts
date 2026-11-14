import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { useDisplayTimezone } from '@/composables/useDisplayTimezone';
import { provideViewTimezone } from '@/composables/useViewTimezone';
import { filled } from '@/lib/helpers';
import { calendar } from '@/routes/app';
import {
    board as channelBoard,
    calendar as channelCalendar,
    publish,
} from '@/routes/app/channels';
import { board, index as postsIndex } from '@/routes/app/posts';
import type { User } from '@/types';
import type { TimezoneOption } from '@/types/posting-schedule';
import type {
    CalendarView,
    PublishChannel,
    PublishScope,
} from '@/types/publish';

export type PublishQuery = Record<string, string | string[] | undefined>;

interface PublishFilterProps {
    scope: PublishScope;
    channel: PublishChannel | null;
    displayTimezone: string;
    timezones: TimezoneOption[];
    filters: {
        labels: string[];
        untagged: boolean;
        channels: string[];
    };
}

interface PublishFilterOptions {
    currentTab?: () => string;
    reloadProps?: () => string[];
}

/**
 * The filters the List, Calendar and Board views of the Publish page share
 * (labels, untagged, channels on the all-channels page, the display time zone)
 * and the links between those views, which carry them. Each page adds only its
 * own query (the List's tab, the Calendar's status and period, the Board's group).
 */
export const usePublishFilters = (
    props: PublishFilterProps,
    options: PublishFilterOptions = {},
) => {
    const page = usePage();

    const userTimezone = computed(
        () => (page.props.auth.user as User).timezone || props.displayTimezone,
    );

    const { timezone, setTimezone, dayKey } = useDisplayTimezone(
        props.displayTimezone,
        props.timezones.map((option) => option.value),
        options.currentTab,
        options.reloadProps,
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

    const filterQuery = (): PublishQuery => ({
        labels: filled(selectedLabelIds.value)
            ? selectedLabelIds.value
            : undefined,
        untagged: selectedUntagged.value ? '1' : undefined,
        channels:
            props.scope === 'all' && filled(selectedChannelIds.value)
                ? selectedChannelIds.value
                : undefined,
        tz: timezone.value !== userTimezone.value ? timezone.value : undefined,
    });

    const listUrlFor = (query: PublishQuery = filterQuery()): string =>
        props.channel
            ? publish.url(props.channel.id, { query })
            : postsIndex.url({ query });

    const calendarUrlFor = (
        view: CalendarView,
        query: PublishQuery = filterQuery(),
    ): string =>
        props.channel
            ? channelCalendar.url({ account: props.channel.id, view }, { query })
            : calendar.url({ view }, { query });

    const boardUrlFor = (query: PublishQuery = filterQuery()): string =>
        props.channel
            ? channelBoard.url(props.channel.id, { query })
            : board.url({ query });

    const isPhone = useBelowBreakpoint('md');

    const listHref = computed((): string => listUrlFor());

    const calendarHref = computed((): string =>
        calendarUrlFor(isPhone.value ? 'days' : 'week'),
    );

    const boardHref = computed((): string => boardUrlFor());

    return {
        timezone,
        setTimezone,
        dayKey,
        selectedLabelIds,
        selectedUntagged,
        selectedChannelIds,
        filterQuery,
        listUrlFor,
        calendarUrlFor,
        boardUrlFor,
        listHref,
        calendarHref,
        boardHref,
    };
};
