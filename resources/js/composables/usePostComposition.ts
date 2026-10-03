import { computed, ref } from 'vue';

import { getContentTypeOptions } from '@/composables/usePlatformLogo';
import { pinterestContentTypeFor } from '@/lib/pinterestContentType';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';
import type { QueuePositionValue, ScheduleModeValue } from '@/types/post';
import type { PostingSchedule } from '@/types/posting-schedule';
import type { SocialAccountStatusValue } from '@/types/social-account-status';

export interface ComposerAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
    status?: SocialAccountStatusValue | null;
    has_posting_schedule?: boolean;
    timezone?: string;
    posting_schedule?: PostingSchedule | null;
    taken_slots?: string[];
}

export interface DestinationDraft {
    social_account_id: string;
    content_type: string;
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
    status: 'draft' | 'scheduled' | 'publishing';
    label_ids: string[];
    destinations: DestinationDraft[];
}

export interface ComposerInitialPost {
    content: string;
    media: MediaItem[];
    scheduled_at: string | null;
    status: string;
    schedule_mode?: ScheduleModeValue | null;
    queue_position?: QueuePositionValue | null;
    social_account_id: string;
    content_type: string;
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

type Override = DestinationOverride;

export interface NetworkGroup {
    key: string;
    platform: string;
    accounts: ComposerAccount[];
    anchor: ComposerAccount;
}

/** Platforms whose settings hold account-owned values (board, channel, creator); they never fan out. */
export const ACCOUNT_SCOPED_SETTINGS: readonly string[] = [
    Platform.Pinterest,
    Platform.Discord,
    Platform.TikTok,
];

const owns = (value: object, key: string): boolean =>
    Object.prototype.hasOwnProperty.call(value, key);

export const usePostComposition = (
    accounts: () => ComposerAccount[],
    initial?: ComposerInitialPost | null,
    initialDraft?: ComposerInitialDraft | null,
) => {
    const content = ref(initial?.content ?? initialDraft?.content ?? '');
    const media = ref<MediaItem[]>(initial?.media ?? initialDraft?.media ?? []);
    const scheduledAt = ref(
        initial?.scheduled_at ?? initialDraft?.scheduled_at ?? '',
    );
    const labelIds = ref(initial?.label_ids ?? initialDraft?.label_ids ?? []);
    const selectedAccountIds = ref<string[]>(
        initial ? [initial.social_account_id] : [],
    );
    const overrides = ref<Record<string, Override>>(
        initial
            ? {
                  [initial.social_account_id]: {
                      content_type: initial.content_type,
                      meta: initial.meta,
                  },
              }
            : {},
    );

    const selectedAccounts = computed(() =>
        selectedAccountIds.value
            .map((id) => accounts().find((account) => account.id === id))
            .filter((account): account is ComposerAccount => Boolean(account)),
    );

    const resolvedDestination = (
        account: ComposerAccount,
    ): DestinationDraft & { content: string; media: MediaItem[] } => {
        const override = overrides.value[account.id] ?? {};
        const meta = override.meta ?? {};
        const isInstagram =
            account.platform === Platform.Instagram ||
            account.platform === Platform.InstagramFacebook;

        const resolvedMedia = owns(override, 'media')
            ? (override.media ?? [])
            : media.value;

        return {
            social_account_id: account.id,
            content_type:
                account.platform === Platform.Pinterest
                    ? pinterestContentTypeFor(resolvedMedia)
                    : (override.content_type ??
                      getContentTypeOptions(account.platform)[0]?.value ??
                      ''),
            meta:
                isInstagram && owns(meta, 'aspect_ratio')
                    ? { ...meta, aspect_ratio: null }
                    : meta,
            content: owns(override, 'content')
                ? (override.content ?? '')
                : content.value,
            media: resolvedMedia,
        };
    };

    const networkGroups = computed<NetworkGroup[]>(() => {
        const members = new Map<string, ComposerAccount[]>();
        const ordered = accounts().filter((account) =>
            selectedAccountIds.value.includes(account.id),
        );

        for (const account of ordered) {
            members.set(account.platform, [
                ...(members.get(account.platform) ?? []),
                account,
            ]);
        }

        return [...members].map(([platform, accounts]) => ({
            key: platform,
            platform,
            accounts,
            anchor: accounts[0],
        }));
    });

    const groupFor = (key: string | null): NetworkGroup | undefined =>
        networkGroups.value.find((group) => group.key === key);

    const ownsKey = (accountId: string, key: keyof Override): boolean =>
        owns(overrides.value[accountId] ?? {}, key);

    const firstOwner = (
        group: NetworkGroup,
        key: keyof Override,
    ): ComposerAccount | undefined =>
        group.accounts.find((account) => ownsKey(account.id, key));

    const customizing = computed(
        () =>
            networkGroups.value.length > 1 &&
            selectedAccountIds.value.some((id) => ownsKey(id, 'content')),
    );

    const writeOverride = (accountId: string, patch: Override): void => {
        overrides.value = {
            ...overrides.value,
            [accountId]: { ...(overrides.value[accountId] ?? {}), ...patch },
        };
    };

    const setGroupOverride = <K extends keyof Override>(
        key: string,
        field: K,
        value: Override[K],
        accountId: string | null = null,
    ): void => {
        const group = groupFor(key);
        if (!group) {
            return;
        }

        if (
            field === 'meta' &&
            ACCOUNT_SCOPED_SETTINGS.includes(group.platform)
        ) {
            const target =
                group.accounts.find((account) => account.id === accountId) ??
                group.anchor;
            writeOverride(target.id, { meta: value } as Override);

            return;
        }

        if (field === 'content' && !customizing.value) {
            content.value = (value as string | undefined) ?? '';

            return;
        }
        if (field === 'media' && !customizing.value) {
            media.value = (value as MediaItem[] | undefined) ?? [];

            return;
        }

        group.accounts.forEach((account) =>
            writeOverride(account.id, { [field]: value } as Override),
        );
    };

    const unifyGroup = (group: NetworkGroup): void => {
        const fields: (keyof Override)[] = [
            'content',
            'media',
            'content_type',
            ...(ACCOUNT_SCOPED_SETTINGS.includes(group.platform)
                ? []
                : (['meta'] as const)),
        ];
        const next = { ...overrides.value };

        fields.forEach((field) => {
            const owner = firstOwner(group, field);
            const source = owner ? (next[owner.id] ?? {}) : null;

            group.accounts.forEach((account) => {
                const own: Override = { ...(next[account.id] ?? {}) };
                if (source && owns(source, field)) {
                    Object.assign(own, {
                        [field]:
                            field === 'media'
                                ? [...(source.media ?? [])]
                                : source[field],
                    });
                } else {
                    delete own[field];
                }
                next[account.id] = own;
            });
        });
        overrides.value = next;
    };

    const promoteSingleGroup = (group: NetworkGroup): void => {
        const contentOwner = firstOwner(group, 'content');
        const mediaOwner = firstOwner(group, 'media');

        if (contentOwner) {
            content.value = overrides.value[contentOwner.id]?.content ?? '';
        }
        if (mediaOwner) {
            media.value = overrides.value[mediaOwner.id]?.media ?? [];
        }

        const next = { ...overrides.value };
        group.accounts.forEach((account) => {
            const rest = { ...(next[account.id] ?? {}) };
            delete rest.content;
            delete rest.media;
            next[account.id] = rest;
        });
        overrides.value = next;
    };

    const seedGroups = (): void => {
        networkGroups.value.forEach((group) => {
            const contentOwner = firstOwner(group, 'content');
            const mediaOwner = firstOwner(group, 'media');
            const groupContent = contentOwner
                ? (overrides.value[contentOwner.id]?.content ?? '')
                : content.value;
            const groupMedia = mediaOwner
                ? (overrides.value[mediaOwner.id]?.media ?? [])
                : media.value;

            group.accounts.forEach((account) =>
                writeOverride(account.id, {
                    content: groupContent,
                    media: [...groupMedia],
                }),
            );
        });
    };

    const normalizeGroups = (): void => {
        const groups = networkGroups.value;

        if (groups.length === 1) {
            promoteSingleGroup(groups[0]);
        } else if (customizing.value) {
            seedGroups();
        }

        groups.forEach(unifyGroup);
    };

    const customize = (): void => {
        if (networkGroups.value.length < 2) {
            return;
        }

        seedGroups();
        networkGroups.value.forEach(unifyGroup);
    };

    const discardCustomization = (): void => {
        overrides.value = {};
    };

    const toggleAccount = (id: string): void => {
        if (initial) {
            return;
        }

        if (selectedAccountIds.value.includes(id)) {
            selectedAccountIds.value = selectedAccountIds.value.filter(
                (selected) => selected !== id,
            );
            const next = { ...overrides.value };
            delete next[id];
            overrides.value = next;
        } else if (accounts().some((account) => account.id === id)) {
            selectedAccountIds.value = [...selectedAccountIds.value, id];
        } else {
            return;
        }

        normalizeGroups();
    };

    const materialize = (
        status: PostComposition['status'],
        queue: QueuePositionValue | null = null,
    ): PostComposition => ({
        content: content.value,
        media: [...media.value],
        scheduled_at:
            status === 'scheduled' && !queue ? scheduledAt.value || null : null,
        queue: status === 'scheduled' ? queue : null,
        status,
        label_ids: [...labelIds.value],
        destinations: selectedAccounts.value.map((account) =>
            resolvedDestination(account),
        ),
    });

    return {
        content,
        media,
        scheduledAt,
        labelIds,
        selectedAccountIds,
        selectedAccounts,
        overrides,
        toggleAccount,
        resolvedDestination,
        materialize,
        networkGroups,
        customizing,
        setGroupOverride,
        normalizeGroups,
        customize,
        discardCustomization,
    };
};
