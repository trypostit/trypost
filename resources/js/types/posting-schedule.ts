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
