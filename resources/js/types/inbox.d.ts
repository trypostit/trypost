import type { MediaType } from '@/lib/mediaType';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

export type InboxView = 'post' | 'list' | 'messages';

export type InboxTab = 'comments' | 'mentions' | 'messages';

export type InboxConversationKind =
    | 'comment'
    | 'review'
    | 'mention'
    | 'direct_message';

export type InboxMessageDirection = 'inbound' | 'outbound';

export type InboxReviewReplyState = 'pending' | 'approved' | 'rejected';

export type InboxReplyLengthUnit = 'characters' | 'bytes';

export type InboxDeliveryStatus = 'sending' | 'sent' | 'failed';

export type InboxStatusFilter = 'all' | 'unanswered' | 'replied' | 'resolved';

export type InboxSort = 'unanswered_first' | 'newest' | 'oldest';
export type InboxPostsFilter = 'commented' | 'all';

export type InboxDateRange = '7d' | '30d' | '6m' | '12m';

export type InboxResolveScope =
    | 'message'
    | 'thread'
    | 'conversation'
    | 'all'
    | 'older';

export interface InboxMediaItem {
    type: MediaType;
    url: string;
    thumbnail_url?: string | null;
}

export type InboxDirectContentType =
    | 'shared_post'
    | 'story_reply'
    | 'story_mention'
    | 'attachment'
    | 'unsupported';

export interface InboxDirectContent {
    type: InboxDirectContentType;
    url?: string;
    title?: string;
    thumbnail_url?: string;
    thumbnail_type?: MediaType;
}

export interface InboxConversationChannel {
    id: string | null;
    platform: string | null;
    username: string | null;
    display_name: string | null;
    avatar_url: string | null;
}

export interface InboxMessage {
    id: string;
    parent_id: string | null;
    root_id: string | null;
    direction: InboxMessageDirection | null;
    origin: 'trypost' | 'network' | null;
    author: {
        name: string | null;
        username: string | null;
        avatar_url: string | null;
    };
    body: string | null;
    media: InboxMediaItem[] | null;
    content?: InboxDirectContent | null;
    reactions?: string[];
    platform_url: string | null;
    platform_created_at: string | null;
    delivery_status: InboxDeliveryStatus | null;
    delivery_error: string | null;
    review_reply_state: InboxReviewReplyState | null;
    rejection_reason: string | null;
    sent_by: { id: string; name: string } | null;
    resolved_at: string | null;
    removed_at: string | null;
    hidden_at: string | null;
    media_pending?: boolean;
    is_open: boolean;
    is_subject?: boolean;
    tree?: InboxMessage[];
    conversation?: InboxConversation;
}

export interface InboxLinkedPost {
    id: string;
    origin: 'trypost' | 'network' | null;
    created_by: string | null;
    created_at: string | null;
    media: Array<{
        id: string | null;
        url: string;
        type: MediaType | null;
        mime_type: string | null;
        meta: { cover_offset_ms: number | null };
    }>;
}

export interface InboxConversation {
    id: string;
    kind: InboxConversationKind | null;
    channel: InboxConversationChannel;
    subject: {
        text: string | null;
        media: InboxMediaItem[] | null;
        url: string | null;
        published_at: string | null;
    };
    post_id: string | null;
    post: InboxLinkedPost | null;
    participant: {
        name: string | null;
        username: string | null;
        avatar_url: string | null;
    };
    rating: number | null;
    open_count: number;
    replied_count?: number;
    inbound_count: number;
    last_message_at: string | null;
    last_inbound_at: string | null;
    reply_window_ends_at: string | null;
    can_send_message: boolean;
    last_message?: InboxMessage | null;
}

/** A card of By post: a conversation, or with "Show all posts" a post nobody has commented on yet (no id). */
export type InboxPostConversation = Omit<InboxConversation, 'id'> & { id: string | null };

export interface InboxChannel {
    id: string;
    platform: string;
    username: string;
    display_name: string | null;
    avatar_url: string | null;
    has_inbox_access: boolean;
    supports_mentions: boolean;
    has_mention_access: boolean;
    is_connected: boolean;
    open_count: number;
    max_reply_length: number;
    prefills_mention: boolean;
    kinds: InboxConversationKind[];
    reply_length_unit: InboxReplyLengthUnit;
    supports_direct_messages: boolean;
    has_direct_message_access: boolean;
    max_message_length: number;
    message_length_unit: InboxReplyLengthUnit | null;
    sync_states?: InboxSyncState[];
}

export type InboxSyncErrorCategory = 'messages_unavailable' | 'permission' | 'failing';

/** How one kind a channel syncs is doing: why it stopped, if it did, and when it last synced. */
export interface InboxSyncState {
    kind: InboxConversationKind;
    sync_error: InboxSyncErrorCategory | null;
    last_synced_at: string | null;
}

/** A kind of a channel whose sync stopped. */
export interface InboxSyncError extends InboxSyncState {
    sync_error: InboxSyncErrorCategory;
}

/** A workspace channel as the channel filter lists it, inbox or not. */
export interface InboxFilterChannel {
    id: string;
    platform: string;
    username: string;
    display_name: string | null;
    avatar_url: string | null;
    status: SocialAccountStatusValue;
    has_inbox: boolean;
}

export interface InboxFilters {
    channels: string[];
    /** The channel whose own inbox page this is (channels/{id}/inbox); the URL holds it, not the channel filter. */
    channel?: string | null;
    status: InboxStatusFilter;
    sort: InboxSort;
    range: InboxDateRange;
    tab: InboxTab;
    posts: InboxPostsFilter;
}

export interface InboxTabCounts {
    comments: number;
    mentions: number | null;
    messages: number | null;
}

export type InboxScoreLevel =
    | 'getting_started'
    | 'finding_your_voice'
    | 'replying_regularly'
    | 'conversing_consistently'
    | 'community_champion';

export interface InboxCommentScore {
    score: number | null;
    level: InboxScoreLevel | null;
    response_rate: number | null;
    response_speed: number | null;
    consistency: number | null;
    comments_count: number;
    answered_count: number;
    window_days: number;
    starts_at: string;
    ends_at: string;
    timezone: string;
}

export interface InboxMentionNetwork {
    platform: string;
    state: 'available' | 'coming_soon';
}

export interface InboxCounts {
    total: number;
    channels: Record<string, number>;
}

export interface InboxScrollProp<T> {
    data: T[];
}
