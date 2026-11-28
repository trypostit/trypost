import type { VerifiedBadge } from '@/types/app/social-account';

export interface PostingScheduleDay {
    day: number;
    enabled: boolean;
    times: string[];
}

export type PostingSchedule = PostingScheduleDay[];

export interface ChannelScheduleState {
    timezone: string;
    posting_goal: number | null;
    posting_schedule: PostingSchedule | null;
}

export interface TimezoneOption {
    value: string;
    label: string;
    offset: string;
}

export interface OtherChannel {
    id: string;
    display_name: string | null;
    username: string;
    platform: string;
    avatar_url: string | null;
    verified_badge?: VerifiedBadge | null;
}

export type ScheduleGenerateAction =
    | { kind: 'goal' }
    | { kind: 'recommended' }
    | { kind: 'copy'; from: string };
