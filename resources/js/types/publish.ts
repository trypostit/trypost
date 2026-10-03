import type { RecurrenceFrequency } from '@/lib/recurrence';
import type { PublicationAnalyticsDetail } from '@/types/analytics';
import type { MediaItem } from '@/types/media';
import type {
    PostOriginValue,
    PostStatusValue,
    QueuePositionValue,
    ScheduleModeValue,
} from '@/types/post';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

export type PublishTab = 'queue' | 'approvals' | 'drafts' | 'sent';

export type PublishScope = 'all' | 'channel';

export type PublishCounts = Record<PublishTab, number>;

export interface PublishSocialAccount {
    id: string;
    platform: string;
    display_name: string | null;
    username: string;
    display_label: string;
    avatar_url: string | null;
    handle_label?: string;
    has_posting_schedule?: boolean;
    timezone?: string;
    status?: SocialAccountStatusValue | null;
}

export interface PublishChannel extends PublishSocialAccount {
    has_posting_schedule: boolean;
    posting_goal: number | null;
    sent_this_week: number;
    scheduled_this_week: number;
    has_grid: boolean;
}

export interface PostCardPlatform {
    id: string;
    social_account_id: string;
    enabled: boolean;
    platform: string;
    status: string;
    platform_url?: string | null;
    error_message?: string | null;
    error_context?: { category?: string | null; failed_at?: string | null } | null;
    social_account: PublishSocialAccount | null;
    content_type?: string;
    meta?: Record<string, any>;
}

export interface PostCardLabel {
    id: string;
    name: string;
    color: string;
}

export interface UnavailablePostMetrics {
    available: false;
    reason: string | null;
}

export type PostCardMetrics = PublicationAnalyticsDetail | UnavailablePostMetrics;

export interface PostCard {
    id: string;
    card_key?: string;
    content: string | null;
    status: PostStatusValue;
    origin: PostOriginValue;
    created_at: string;
    updated_at: string;
    scheduled_at: string | null;
    schedule_mode?: ScheduleModeValue | null;
    approval_requested_at?: string | null;
    approval_queue_position?: QueuePositionValue | null;
    approved_at?: string | null;
    post_group_id?: string | null;
    group_posts_count?: number;
    recurrence_interval?: number | null;
    recurrence_frequency?: RecurrenceFrequency | null;
    recurrence_remaining?: number | null;
    recurrence_origin_at?: string | null;
    published_at: string | null;
    user: { name: string } | null;
    post_platforms: PostCardPlatform[];
    labels: PostCardLabel[];
    notes_count: number;
    media?: MediaItem[];
    can_delete: boolean;
    metrics?: Record<string, PostCardMetrics | null>;
}

export interface QueueItem {
    type: 'post' | 'slot';
    at: string;
    channel_id: string;
    post_id: string | null;
}

export interface QueueDay {
    date: string;
    items: QueueItem[];
}

export interface QueuePostPosition {
    canMoveUp: boolean;
    canMoveDown: boolean;
}

export interface PublishQueue {
    days: QueueDay[];
    pending: PostCard[];
    queueDays: number;
    maxQueueDays: number;
}

export interface QueueView {
    days: QueueDay[];
    posts: Record<string, PostCard>;
}

export interface ScrollPostCards {
    data: PostCard[];
}

export type PostCardMove = 'top' | 'up' | 'down';

export type PostScheduleAction = 'draft' | 'publish_now' | 'queue_next' | 'queue_top';

export type PostCardMenuAction =
    | 'publish_now'
    | 'move_top'
    | 'move_up'
    | 'move_down'
    | 'duplicate'
    | 'recurrence'
    | 'move_drafts'
    | 'details'
    | 'delete';

export interface CalendarPost extends PostCard {
    calendar_at: string;
}

export type CalendarView = 'week' | 'month';

export type CalendarStatus = 'all' | 'drafts' | 'scheduled' | 'sent';

export interface CalendarSlot {
    at: string;
    channel_id: string;
}

export interface UndatedDraft extends Omit<PostCard, 'scheduled_at'> {
    scheduled_at: null;
}

export interface ScrollUndatedDrafts {
    data: UndatedDraft[];
}
