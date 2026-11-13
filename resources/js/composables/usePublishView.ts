import { computed, type ComputedRef } from 'vue';

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
    channelPublishPaths: (channelId: string) => string[];
} => {
    const stored = useUserStorage('publish.view', 'list');
    const isPhone = useBelowBreakpoint('md');

    const publishView = computed((): PublishView =>
        PUBLISH_VIEWS.includes(stored.value) ? stored.value : 'list',
    );

    const calendarView = computed((): string => (isPhone.value ? 'days' : 'week'));

    const rememberPublishView = (view: PublishView): void => {
        if (stored.value !== view) {
            stored.value = view;
        }
    };

    const publishUrl = computed((): string => {
        if (publishView.value === 'calendar') {
            return calendar.url({ view: calendarView.value });
        }

        return publishView.value === 'board' ? board.url() : postsIndex.url();
    });

    const channelPublishUrl = (channelId: string): string => {
        if (publishView.value === 'calendar') {
            return channelCalendar.url({ account: channelId, view: calendarView.value });
        }

        return publishView.value === 'board'
            ? channelBoard.url(channelId)
            : channelPublish.url(channelId);
    };

    /** Every path of a channel's Publish views, to mark its Publish link as current on any of them. */
    const channelPublishPaths = (channelId: string): string[] => [
        channelPublish.url(channelId),
        channelCalendar.url({ account: channelId }),
        channelBoard.url(channelId),
    ];

    return {
        publishView,
        rememberPublishView,
        publishUrl,
        channelPublishUrl,
        channelPublishPaths,
    };
};
