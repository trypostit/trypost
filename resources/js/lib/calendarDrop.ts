import dayjs from '@/dayjs';
import { PostStatus, ScheduleMode } from '@/types/post';
import type { PostCard } from '@/types/publish';

export type CalendarDropTarget =
    | { kind: 'hour'; at: string }
    | { kind: 'day'; dayKey: string }
    | { kind: 'slot'; at: string; channelId: string; queueable: boolean }
    | { kind: 'undated' };

const DRAFT_DAY_CLOCK = '09:00';

export const canDragCalendarPost = (
    post: PostCard,
    now: dayjs.Dayjs,
    canCreatePost: boolean,
): boolean =>
    canCreatePost &&
    post.platform !== null &&
    (post.status === PostStatus.Draft ||
        (post.status === PostStatus.Scheduled &&
            post.scheduled_at !== null &&
            dayjs.utc(post.scheduled_at).isAfter(now.add(1, 'minute'))));

const SOON_MINUTES = 5;

/**
 * Five minutes from now, to the minute: where a drop lands when the time it
 * names has already started (today on the month grid, the current hour).
 */
const soon = (now: dayjs.Dayjs): dayjs.Dayjs =>
    now.add(SOON_MINUTES, 'minute').startOf('minute');

const instantOnDay = (
    post: PostCard,
    dayKey: string,
    timezone: string,
    now: dayjs.Dayjs,
): dayjs.Dayjs | undefined => {
    const today = now.tz(timezone).format('YYYY-MM-DD');

    if (dayKey < today) {
        return undefined;
    }

    const clock = post.scheduled_at
        ? dayjs.utc(post.scheduled_at).tz(timezone).format('HH:mm:ss')
        : `${DRAFT_DAY_CLOCK}:00`;
    const instant = dayjs.tz(`${dayKey}T${clock}`, timezone);

    if (instant.format('HH:mm:ss') !== clock) {
        return undefined;
    }

    if (instant.isAfter(now)) {
        return instant;
    }

    const later = soon(now);

    return later.tz(timezone).format('YYYY-MM-DD') === dayKey
        ? later
        : undefined;
};

/**
 * An hour cell takes the start of its hour, or, for the hour under way, five
 * minutes from now while that is still inside the hour.
 */
const instantInHour = (
    at: string,
    now: dayjs.Dayjs,
): dayjs.Dayjs | undefined => {
    const start = dayjs.utc(at);

    if (start.isAfter(now)) {
        return start;
    }

    const later = soon(now);

    return later.isBefore(start.add(1, 'hour')) ? later : undefined;
};

const isInHourOf = (
    post: PostCard,
    at: string,
    timezone: string,
): boolean => {
    if (!post.scheduled_at) {
        return false;
    }

    const hourOf = (instant: string): string =>
        dayjs.utc(instant).tz(timezone).format('YYYY-MM-DD HH');

    return hourOf(post.scheduled_at) === hourOf(at);
};

export const dropInstant = (
    post: PostCard,
    target: CalendarDropTarget,
    timezone: string,
    now: dayjs.Dayjs,
): string | null | undefined => {
    if (target.kind === 'undated') {
        return post.scheduled_at !== null ? null : undefined;
    }

    if (target.kind === 'hour' && isInHourOf(post, target.at, timezone)) {
        return undefined;
    }

    const instant =
        target.kind === 'day'
            ? instantOnDay(post, target.dayKey, timezone, now)
            : target.kind === 'hour'
              ? instantInHour(target.at, now)
              : dayjs.utc(target.at);

    if (
        !instant ||
        !instant.isAfter(now) ||
        (post.scheduled_at && dayjs.utc(post.scheduled_at).isSame(instant))
    ) {
        return undefined;
    }

    return instant.utc().format();
};

export const entersQueueSlot = (
    post: PostCard,
    target: CalendarDropTarget,
): boolean =>
    post.status === PostStatus.Scheduled &&
    target.kind === 'slot' &&
    target.queueable &&
    target.channelId === post.social_account_id;

export const needsCustomTimeConfirmation = (
    post: PostCard,
    target: CalendarDropTarget,
): boolean =>
    post.schedule_mode === ScheduleMode.Queue &&
    post.status === PostStatus.Scheduled &&
    !entersQueueSlot(post, target);
