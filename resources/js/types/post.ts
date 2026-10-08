export const PostStatus = {
    Draft: 'draft',
    PendingApproval: 'pending_approval',
    Scheduled: 'scheduled',
    Publishing: 'publishing',
    Published: 'published',
    Failed: 'failed',
} as const;

export type PostStatusValue = (typeof PostStatus)[keyof typeof PostStatus];

export const PublishStatus = {
    Pending: 'pending',
    Publishing: 'publishing',
    Published: 'published',
    Failed: 'failed',
    Retrying: 'retrying',
    Rejected: 'rejected',
    PendingReview: 'pending_review',
} as const;

export type PublishStatusValue = (typeof PublishStatus)[keyof typeof PublishStatus];

export const ScheduleMode = {
    Queue: 'queue',
    Custom: 'custom',
} as const;

export type ScheduleModeValue = (typeof ScheduleMode)[keyof typeof ScheduleMode];

export const PostOrigin = {
    TryPost: 'trypost',
    Network: 'network',
} as const;

export type PostOriginValue = (typeof PostOrigin)[keyof typeof PostOrigin];

export type QueuePositionValue = 'next' | 'top';
