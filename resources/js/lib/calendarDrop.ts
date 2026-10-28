import dayjs from '@/dayjs';
import { PostStatus, ScheduleMode } from '@/types/post';
import type { PostCard } from '@/types/publish';

export type CalendarDropTarget =
    | { kind: 'hour'; at: string }
    | { kind: 'day'; dayKey: string }
    | { kind: 'slot'; at: string; channelId: string }
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

    const soon = now.add(5, 'minute').startOf('minute');

    return soon.tz(timezone).format('YYYY-MM-DD') === dayKey ? soon : undefined;
};

const isInHourOf = (post: PostCard, at: string): boolean => {
    if (!post.scheduled_at) {
        return false;
    }

    const start = dayjs.utc(at);
    const current = dayjs.utc(post.scheduled_at);

    return !current.isBefore(start) && current.isBefore(start.add(1, 'hour'));
};

export const dropInstant = (
    post: PostCard,
    target: CalendarDropTarget,
    timezone: string,
    now: dayjs.Dayjs,
): string | null | undefined => {
    if (target.kind === 'undated') {
        return post.status === PostStatus.Draft && post.scheduled_at !== null
            ? null
            : undefined;
    }

    if (target.kind === 'hour' && isInHourOf(post, target.at)) {
        return undefined;
    }

    const instant =
        target.kind === 'day'
            ? instantOnDay(post, target.dayKey, timezone, now)
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
    target.channelId === post.social_account_id;

export const needsCustomTimeConfirmation = (
    post: PostCard,
    target: CalendarDropTarget,
): boolean =>
    post.schedule_mode === ScheduleMode.Queue &&
    post.status === PostStatus.Scheduled &&
    !entersQueueSlot(post, target);
