import type { PostCardLabel, PublishSocialAccount } from '@/types/publish';

export type BoardGroup = 'status' | 'tag' | 'channel';

export type BoardColumnType = 'status' | 'label' | 'untagged' | 'channel';

export interface BoardColumnMeta {
    key: string;
    type: BoardColumnType;
    label?: PostCardLabel;
    channel?: PublishSocialAccount;
}
