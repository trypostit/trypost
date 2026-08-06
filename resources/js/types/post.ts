export const PostStatus = {
    Draft: 'draft',
    Scheduled: 'scheduled',
    Publishing: 'publishing',
    Published: 'published',
    PartiallyPublished: 'partially_published',
    Failed: 'failed',
} as const;

export type PostStatusValue = (typeof PostStatus)[keyof typeof PostStatus];

export const PostPublishMode = {
    Auto: 'auto',
    Manual: 'manual',
} as const;

export type PostPublishModeValue = (typeof PostPublishMode)[keyof typeof PostPublishMode];

export const PostPlatformStatus = {
    Pending: 'pending',
    Publishing: 'publishing',
    Published: 'published',
    Failed: 'failed',
    Retrying: 'retrying',
} as const;

export type PostPlatformStatusValue = (typeof PostPlatformStatus)[keyof typeof PostPlatformStatus];
