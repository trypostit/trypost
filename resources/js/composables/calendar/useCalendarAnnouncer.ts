import { trans } from 'laravel-vue-i18n';
import { ref, type Ref } from 'vue';

import type { CalendarDragLifecycle } from '@/composables/calendar/useCalendarDrag';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import dayjs from '@/dayjs';
import type { CalendarDropTarget } from '@/lib/calendarDrop';
import type { PostCard } from '@/types/publish';

export const useCalendarAnnouncer = (
    timezone: Readonly<Ref<string>>,
): {
    announcement: Ref<string>;
    describeInstant: (
        at: string,
        target: CalendarDropTarget,
        previousAt: string | null,
    ) => string;
    hooks: Required<CalendarDragLifecycle>;
} => {
    const announcement = ref('');
    let lastOverAnnouncement = '';

    const describeAt = (at: string, dateOnly: boolean): string => {
        const day = date.formatWeekdayMonthDay(at, timezone.value);

        return dateOnly
            ? day
            : trans('calendar.reschedule.announce.date_time', {
                  date: day,
                  time: date.formatTimeInTimezone(at, timezone.value),
              });
    };

    const clockOf = (at: string): string =>
        dayjs.utc(at).tz(timezone.value).format('HH:mm');

    /**
     * A day drop keeps the post's clock time, so it names the date only, unless
     * the time changed too (today after that time moves it to a few minutes
     * from now), which the user has to hear.
     */
    const describeInstant = (
        at: string,
        target: CalendarDropTarget,
        previousAt: string | null,
    ): string =>
        describeAt(
            at,
            target.kind === 'day' &&
                (previousAt === null || clockOf(previousAt) === clockOf(at)),
        );

    const describeTarget = (target: CalendarDropTarget): string => {
        switch (target.kind) {
            case 'undated':
                return trans('calendar.no_date');
            case 'day':
                return describeAt(
                    dayjs.tz(target.dayKey, timezone.value).utc().format(),
                    true,
                );
            default:
                return describeAt(target.at, false);
        }
    };

    const channelLabel = (post: PostCard): string =>
        post.social_account?.display_label ??
        (post.platform ? getPlatformLabel(post.platform) : '');

    const announce = (message: string): void => {
        lastOverAnnouncement = '';
        announcement.value = message;
    };

    const announceOver = (
        post: PostCard,
        target: CalendarDropTarget | null,
        accepts: boolean,
    ): void => {
        if (!target || !accepts) {
            return;
        }

        const message = post.scheduled_at
            ? trans('calendar.reschedule.announce.over', {
                  from: describeAt(post.scheduled_at, false),
                  target: describeTarget(target),
              })
            : trans('calendar.reschedule.announce.over_undated', {
                  channel: channelLabel(post),
                  target: describeTarget(target),
              });

        if (message === lastOverAnnouncement) {
            return;
        }

        lastOverAnnouncement = message;
        announcement.value = message;
    };

    const announceDrop = (
        post: PostCard,
        target: CalendarDropTarget,
        scheduledAt: string | null,
    ): void => {
        if (scheduledAt === null) {
            announce(
                trans('calendar.reschedule.announce.undated', {
                    from: describeAt(post.scheduled_at ?? '', false),
                }),
            );

            return;
        }

        const to = describeInstant(scheduledAt, target, post.scheduled_at);

        announce(
            post.scheduled_at
                ? trans('calendar.reschedule.announce.moved', {
                      from: describeAt(post.scheduled_at, false),
                      to,
                  })
                : trans('calendar.reschedule.announce.from_undated', {
                      channel: channelLabel(post),
                      to,
                  }),
        );
    };

    const clearAnnouncement = (): void => {
        lastOverAnnouncement = '';
        announcement.value = '';
    };

    const announceReturn = (): void =>
        announce(trans('calendar.reschedule.announce.returned'));

    return {
        announcement,
        describeInstant,
        hooks: {
            onDragStart: clearAnnouncement,
            onOver: announceOver,
            onConfirmed: announceDrop,
            onRejected: announceReturn,
            onCancel: announceReturn,
        },
    };
};
