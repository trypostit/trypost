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
    !post.recurrence_frequency &&
    (post.status === PostStatus.Draft ||
        (post.status === PostStatus.Scheduled &&
            post.scheduled_at !== null &&
            dayjs.utc(post.scheduled_at).isAfter(now.add(1, 'minute'))));

export const isFutureInstant = (at: string, now: dayjs.Dayjs): boolean =>
    dayjs.utc(at).isAfter(now);

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

    return instant.isAfter(now)
        ? instant
        : now.add(5, 'minute').startOf('minute');
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

    if (!instant || !isFutureInstant(instant.utc().format(), now)) {
        return undefined;
    }

    const result = instant.utc().format();

    return post.scheduled_at && dayjs.utc(post.scheduled_at).isSame(result)
        ? undefined
        : result;
};

export const needsCustomTimeConfirmation = (
    post: PostCard,
    target: CalendarDropTarget,
): boolean =>
    post.schedule_mode === ScheduleMode.Queue &&
    post.status === PostStatus.Scheduled &&
    !(target.kind === 'slot' && target.channelId === post.social_account_id);
