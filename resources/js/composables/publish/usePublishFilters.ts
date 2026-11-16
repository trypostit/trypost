import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import { useDisplayTimezone } from '@/composables/useDisplayTimezone';
import { useOpeningCalendarView } from '@/composables/usePublishView';
import { provideViewTimezone } from '@/composables/useViewTimezone';
import { blank, filled } from '@/lib/helpers';
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

    /** The signed-in user's zone; the page's display zone for a user without one. */
    const userTimezone = computed((): string => {
        const own = (page.props.auth.user as User).timezone;

        if (filled(own)) {
            return own;
        }

        return props.displayTimezone;
    });

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

    const labelsQuery = (): string[] | undefined => {
        if (blank(selectedLabelIds.value)) {
            return undefined;
        }

        return selectedLabelIds.value;
    };

    const untaggedQuery = (): string | undefined => {
        if (!selectedUntagged.value) {
            return undefined;
        }

        return '1';
    };

    /** Channels are a filter of the all-channels page only; a channel page is already one channel. */
    const channelsQuery = (): string[] | undefined => {
        if (props.scope !== 'all') {
            return undefined;
        }

        if (blank(selectedChannelIds.value)) {
            return undefined;
        }

        return selectedChannelIds.value;
    };

    /** The zone goes in the URL only when it is not the user's own. */
    const timezoneQuery = (): string | undefined => {
        if (timezone.value === userTimezone.value) {
            return undefined;
        }

        return timezone.value;
    };

    const filterQuery = (): PublishQuery => ({
        labels: labelsQuery(),
        untagged: untaggedQuery(),
        channels: channelsQuery(),
        tz: timezoneQuery(),
    });

    const listUrlFor = (query: PublishQuery = filterQuery()): string => {
        if (filled(props.channel)) {
            return publish.url(props.channel.id, { query });
        }

        return postsIndex.url({ query });
    };

    const calendarUrlFor = (
        view: CalendarView,
        query: PublishQuery = filterQuery(),
    ): string => {
        if (filled(props.channel)) {
            return channelCalendar.url(
                { account: props.channel.id, view },
                { query },
            );
        }

        return calendar.url({ view }, { query });
    };

    const boardUrlFor = (query: PublishQuery = filterQuery()): string => {
        if (filled(props.channel)) {
            return channelBoard.url(props.channel.id, { query });
        }

        return board.url({ query });
    };

    const calendarView = useOpeningCalendarView();

    const listHref = computed((): string => listUrlFor());

    const calendarHref = computed((): string =>
        calendarUrlFor(calendarView.value),
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
