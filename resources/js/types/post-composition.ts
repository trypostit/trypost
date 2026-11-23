import type { ContentTypeValue } from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import type { PlatformValue } from '@/types/platform';
import type {
    PostStatus,
    QueuePositionValue,
    ScheduleModeValue,
} from '@/types/post';
import type { PostingSchedule } from '@/types/posting-schedule';
import type { VerifiedBadge } from '@/types/social-account';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

export interface ComposerAccount {
    id: string;
    platform: PlatformValue;
    display_name: string;
    username: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
    verified_badge?: VerifiedBadge | null;
    status?: SocialAccountStatusValue | null;
    has_posting_schedule?: boolean;
    timezone?: string;
    posting_schedule?: PostingSchedule | null;
}

export interface DestinationDraft {
    social_account_id: string;
    content_type: ContentTypeValue;
    meta: Record<string, any>;
    content?: string;
    media?: MediaItem[];
}

export interface PostComposition {
    content: string;
    media: MediaItem[];
    scheduled_at: string | null;
    queue?: QueuePositionValue | null;
    queue_slot?: string;
    status:
        | typeof PostStatus.Draft
        | typeof PostStatus.Scheduled
        | typeof PostStatus.Publishing;
    label_ids: string[];
    destinations: DestinationDraft[];
}

/** Saves the composition and returns whether the save started. */
export type SubmitPostComposition = (
    status: PostComposition['status'],
    onSaved?: () => void,
    leavesApp?: boolean,
) => boolean;

export interface ComposerInitialPost {
    content: string;
    media: MediaItem[];
    scheduled_at: string | null;
    status: string;
    schedule_mode?: ScheduleModeValue | null;
    queue_position?: QueuePositionValue | null;
    social_account_id: string;
    content_type?: ContentTypeValue;
    meta: Record<string, any>;
    label_ids: string[];
}

export interface ComposerInitialDraft {
    content: string;
    media: MediaItem[];
    scheduled_at: string | null;
    label_ids: string[];
}

export type DestinationOverride = Partial<
    Pick<DestinationDraft, 'content' | 'media' | 'content_type' | 'meta'>
>;

export interface NetworkGroup {
    key: string;
    platform: PlatformValue;
    accounts: ComposerAccount[];
    anchor: ComposerAccount;
}
