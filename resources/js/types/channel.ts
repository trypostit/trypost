import type { PinterestBoard } from '@/types';

export interface ChannelAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

export interface ChannelTikTokCreatorInfo {
    creator_nickname: string | null;
    creator_username: string | null;
    creator_avatar_url: string | null;
    privacy_level_options: string[];
    comment_disabled: boolean;
    duet_disabled: boolean;
    stitch_disabled: boolean;
    max_video_post_duration_sec: number | null;
}

/**
 * One selectable publishing channel, shared by the post editor's channels tab
 * and the automation Generate node. `id` is the selection/update key (a
 * post_platform id in the editor, a social account id in automations);
 * `socialAccount` is what the per-platform Settings components consume.
 */
export interface Channel {
    id: string;
    platform: string;
    displayName: string;
    username: string | null;
    avatarUrl: string | null;
    socialAccount: ChannelAccount | null;
    contentType: string;
    meta: Record<string, any>;
    issue?: string | null;
    status?: string | null;
    contentTypeError?: string;
    publishConfig?: Record<string, any> | null;
    creatorInfo?: ChannelTikTokCreatorInfo | null;
    boards?: PinterestBoard[];
    boardsTruncated?: boolean;
}
