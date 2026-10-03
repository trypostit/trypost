import dayjs from '@/dayjs';
import type { CalendarPost, CalendarSlot } from '@/types/publish';

export interface CalendarItem {
    key: string;
    at: string;
    post?: CalendarPost;
    slot?: CalendarSlot;
}

export const calendarItems = (
    posts: CalendarPost[],
    slots: CalendarSlot[],
): CalendarItem[] =>
    [
        ...posts.map((post) => ({
            key: `post-${post.id}`,
            at: post.calendar_at,
            post,
        })),
        ...slots.map((slot) => ({
            key: `slot-${slot.channel_id}-${slot.at}`,
            at: slot.at,
            slot,
        })),
    ].sort((first, second) => dayjs(first.at).diff(dayjs(second.at)));
