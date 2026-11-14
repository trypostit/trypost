import { computed, type ComputedRef } from 'vue';

import { useActiveUrl } from '@/composables/useActiveUrl';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { useUserStorage } from '@/composables/useBrowserStorage';
import { calendar } from '@/routes/app';
import {
    board as channelBoard,
    calendar as channelCalendar,
    publish as channelPublish,
} from '@/routes/app/channels';
import { board, index as postsIndex } from '@/routes/app/posts';
import { PUBLISH_VIEWS, type PublishView } from '@/types/publish';

/**
 * The Publish view (list, calendar or board) the user last opened, kept in the
 * browser storage, so the Publish links of the menu and of each channel open it again.
 */
export const usePublishView = (): {
    publishView: ComputedRef<PublishView>;
    rememberPublishView: (view: PublishView) => void;
    publishUrl: ComputedRef<string>;
    channelPublishUrl: (channelId: string) => string;
    isChannelPublishPage: (channelId: string) => boolean;
} => {
    const stored = useUserStorage('publish.view', 'list');
    const isPhone = useBelowBreakpoint('md');
    const { urlIsActive } = useActiveUrl();

    /** The stored view when it is still one of the views, else the list. */
    const publishView = computed((): PublishView => {
        if (PUBLISH_VIEWS.includes(stored.value)) {
            return stored.value;
        }

        return 'list';
    });

    /** The calendar opens on days on a phone, on the week elsewhere. */
    const calendarView = computed((): string => {
        if (isPhone.value) {
            return 'days';
        }

        return 'week';
    });

    const rememberPublishView = (view: PublishView): void => {
        if (stored.value !== view) {
            stored.value = view;
        }
    };

    const publishUrl = computed((): string => {
        if (publishView.value === 'calendar') {
            return calendar.url({ view: calendarView.value });
        }

        if (publishView.value === 'board') {
            return board.url();
        }

        return postsIndex.url();
    });

    const channelPublishUrl = (channelId: string): string => {
        if (publishView.value === 'calendar') {
            return channelCalendar.url({ account: channelId, view: calendarView.value });
        }

        if (publishView.value === 'board') {
            return channelBoard.url(channelId);
        }

        return channelPublish.url(channelId);
    };

    /** Whether the page is one of a channel's Publish views, so its Publish link shows as current on any of them. */
    const isChannelPublishPage = (channelId: string): boolean =>
        [
            channelPublish.url(channelId),
            channelCalendar.url({ account: channelId }),
            channelBoard.url(channelId),
        ].some((url) => urlIsActive(url));

    return {
        publishView,
        rememberPublishView,
        publishUrl,
        channelPublishUrl,
        isChannelPublishPage,
    };
};
