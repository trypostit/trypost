import { computed, ref } from 'vue';

import { getContentTypeOptions } from '@/composables/usePlatformLogo';
import type { MediaItem } from '@/types/media';

export interface ComposerAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
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
    status: 'draft' | 'scheduled' | 'publishing';
    label_ids: string[];
    destinations: DestinationDraft[];
}

export interface ComposerInitialPost {
    content: string;
    media: MediaItem[];
    scheduled_at: string | null;
    status: string;
    social_account_id: string;
    content_type: string;
    meta: Record<string, any>;
    label_ids: string[];
}

type Override = Partial<
    Pick<DestinationDraft, 'content' | 'media' | 'content_type' | 'meta'>
>;

const owns = (value: object, key: string): boolean =>
    Object.prototype.hasOwnProperty.call(value, key);

export const usePostComposition = (
    accounts: () => ComposerAccount[],
    initial?: ComposerInitialPost | null,
) => {
    const content = ref(initial?.content ?? '');
    const media = ref<MediaItem[]>(initial?.media ?? []);
    const scheduledAt = ref(initial?.scheduled_at ?? '');
    const labelIds = ref(initial?.label_ids ?? []);
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
        }
    };

    const setOverride = (
        id: string,
        field: keyof Override,
        value: Override[keyof Override],
    ): void => {
        if (!selectedAccountIds.value.includes(id)) {
            return;
        }

        overrides.value = {
            ...overrides.value,
            [id]: { ...(overrides.value[id] ?? {}), [field]: value },
        };
    };

    const clearOverride = (id: string, field: keyof Override): void => {
        const next = { ...(overrides.value[id] ?? {}) };
        delete next[field];
        overrides.value = { ...overrides.value, [id]: next };
    };

    const resolvedDestination = (
        account: ComposerAccount,
    ): DestinationDraft & { content: string; media: MediaItem[] } => {
        const override = overrides.value[account.id] ?? {};
        return {
            social_account_id: account.id,
            content_type:
                override.content_type ??
                getContentTypeOptions(account.platform)[0]?.value ??
                '',
            meta: override.meta ?? {},
            content: owns(override, 'content')
                ? (override.content ?? '')
                : content.value,
            media: owns(override, 'media')
                ? (override.media ?? [])
                : media.value,
        };
    };

    const materialize = (
        status: PostComposition['status'],
    ): PostComposition => ({
        content: content.value,
        media: [...media.value],
        scheduled_at: status === 'scheduled' ? scheduledAt.value || null : null,
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
        setOverride,
        clearOverride,
        resolvedDestination,
        materialize,
    };
};
