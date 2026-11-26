import { computed, ref } from 'vue';

import { getContentTypeOptions } from '@/composables/usePlatformLogo';
import { derivedContentTypeFor } from '@/lib/derivedContentType';
import { blank } from '@/lib/helpers';
import {
    isEditedMedia,
    uneditedMedia,
    withMediaEdits,
    withoutOriginal,
} from '@/lib/mediaEdits';
import type { MediaItem } from '@/types/media';
import { Platform, type PlatformValue } from '@/types/platform';
import {
    PostStatus,
    type QueuePositionValue,
} from '@/types/post';
import type {
    ComposerAccount,
    ComposerInitialDraft,
    ComposerInitialPost,
    DestinationDraft,
    DestinationOverride,
    NetworkGroup,
    PostComposition,
} from '@/types/post-composition';

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
    const overrides = ref<Record<string, DestinationOverride>>(
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

    /** A network's media: its own list once customized, else the shared items with its edits. */
    const destinationMedia = (override: DestinationOverride): MediaItem[] => {
        if (owns(override, 'media')) {
            return override.media ?? [];
        }

        return withMediaEdits(media.value, override.media_edits ?? []);
    };

    const resolvedDestination = (
        account: ComposerAccount,
    ): DestinationDraft & { content: string; media: MediaItem[] } => {
        const override = overrides.value[account.id] ?? {};
        const meta = override.meta ?? {};
        const resolvedMedia = destinationMedia(override);

        return {
            social_account_id: account.id,
            content_type:
                derivedContentTypeFor(account.platform, resolvedMedia) ??
                override.content_type ??
                getContentTypeOptions(account.platform)[0]?.value ??
                '',
            meta,
            content: owns(override, 'content')
                ? (override.content ?? '')
                : content.value,
            media: resolvedMedia,
        };
    };

    const networkGroups = computed<NetworkGroup[]>(() => {
        const members = new Map<PlatformValue, ComposerAccount[]>();
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

    const ownsKey = (accountId: string, key: keyof DestinationOverride): boolean =>
        owns(overrides.value[accountId] ?? {}, key);

    const firstOwner = (
        group: NetworkGroup,
        key: keyof DestinationOverride,
    ): ComposerAccount | undefined =>
        group.accounts.find((account) => ownsKey(account.id, key));

    const customizing = computed(
        () =>
            networkGroups.value.length > 1 &&
            selectedAccountIds.value.some((id) => ownsKey(id, 'content')),
    );

    const writeOverride = (accountId: string, patch: DestinationOverride): void => {
        overrides.value = {
            ...overrides.value,
            [accountId]: { ...(overrides.value[accountId] ?? {}), ...patch },
        };
    };

    const setGroupOverride = <K extends keyof DestinationOverride>(
        key: string,
        field: K,
        value: DestinationOverride[K],
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
            writeOverride(target.id, { meta: value } as DestinationOverride);

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
            writeOverride(account.id, { [field]: value } as DestinationOverride),
        );
    };

    const copiedField = (
        source: DestinationOverride,
        field: keyof DestinationOverride,
    ): DestinationOverride[keyof DestinationOverride] => {
        if (field === 'media') {
            return [...(source.media ?? [])];
        }

        if (field === 'media_edits') {
            return [...(source.media_edits ?? [])];
        }

        return source[field];
    };

    /** The media a group's posts get while the text is shared: the shared items with the group's edits. */
    const editedSharedMedia = (group: NetworkGroup): MediaItem[] => {
        const owner = firstOwner(group, 'media_edits');

        if (!owner) {
            return media.value;
        }

        return withMediaEdits(
            media.value,
            overrides.value[owner.id]?.media_edits ?? [],
        );
    };

    const unifyGroup = (group: NetworkGroup): void => {
        const fields: (keyof DestinationOverride)[] = [
            'content',
            'media',
            'media_edits',
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
                const own: DestinationOverride = { ...(next[account.id] ?? {}) };
                if (source && owns(source, field)) {
                    Object.assign(own, {
                        [field]: copiedField(source, field),
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
        } else {
            media.value = editedSharedMedia(group);
        }

        const next = { ...overrides.value };
        group.accounts.forEach((account) => {
            const rest = { ...(next[account.id] ?? {}) };
            delete rest.content;
            delete rest.media;
            delete rest.media_edits;
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
                : editedSharedMedia(group);

            group.accounts.forEach((account) =>
                writeOverride(account.id, {
                    content: groupContent,
                    media: [...groupMedia],
                }),
            );
        });
    };

    /**
     * A second network joins a post edited for one: the edits stay with the
     * network they were made on, and the shared media goes back to the items
     * as they were added, which is what the new network starts from.
     */
    const keepEditsWith = (group: NetworkGroup): void => {
        const edits = media.value.filter(isEditedMedia);

        if (blank(edits)) {
            return;
        }

        group.accounts.forEach((account) =>
            writeOverride(account.id, { media_edits: edits }),
        );
        media.value = media.value.map(uneditedMedia);
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

    /**
     * Back to step 1 drops what was customized per network, but each network
     * keeps the media edits it had before (made while it was the only one).
     */
    const discardCustomization = (): void => {
        overrides.value = Object.fromEntries(
            Object.entries(overrides.value).flatMap(([accountId, override]) => {
                if (!owns(override, 'media_edits')) {
                    return [];
                }

                return [[accountId, { media_edits: override.media_edits }]];
            }),
        );
    };

    const toggleAccount = (id: string): void => {
        if (initial) {
            return;
        }

        const [single] = networkGroups.value;
        const wasSingle = networkGroups.value.length === 1;

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

        if (wasSingle && networkGroups.value.length > 1 && !customizing.value) {
            keepEditsWith(single);
        }

        if (wasSingle && blank(networkGroups.value)) {
            media.value = media.value.map(uneditedMedia);
        }

        normalizeGroups();
    };

    const materialize = (
        status: PostComposition['status'],
        queue: QueuePositionValue | null = null,
    ): PostComposition => ({
        content: content.value,
        media: media.value.map(withoutOriginal),
        scheduled_at:
            status === PostStatus.Scheduled && !queue ? scheduledAt.value || null : null,
        queue: status === PostStatus.Scheduled ? queue : null,
        status,
        label_ids: [...labelIds.value],
        destinations: selectedAccounts.value.map((account) => {
            const destination = resolvedDestination(account);

            return {
                ...destination,
                media: destination.media.map(withoutOriginal),
            };
        }),
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
