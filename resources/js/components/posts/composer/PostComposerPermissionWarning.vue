<script setup lang="ts">
import { computed } from 'vue';

import TransSlots from '@/components/TransSlots.vue';
import WarningLine from '@/components/WarningLine.vue';
import WarningLink from '@/components/WarningLink.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import {
    channelPermissionKey,
    mediaHasVideoCover,
} from '@/lib/channelPermissions';
import { filled } from '@/lib/helpers';
import { channelName } from '@/types/channel';
import type { ContentTypeValue } from '@/types/content-type';
import type { MediaItem } from '@/types/media';
import type { PlatformConfigs } from '@/types/platform';
import type { ComposerAccount } from '@/types/post-composition';

/**
 * Why a card cannot go out: one of its channels lacks the scope a feature
 * the post uses needs (`missingPermissions` of its platform config). It names
 * the first such channel when the card has several; someone who manages
 * channels gets a link that saves the post and reconnects that channel
 * (never on a post waiting for approval, which is decided on first), anyone
 * else is told who can.
 */
const props = withDefaults(
    defineProps<{
        accounts: ComposerAccount[];
        contentType: ContentTypeValue | null;
        platformConfigs: PlatformConfigs;
        meta?: Record<string, unknown> | null;
        media?: MediaItem[];
        disabled?: boolean;
        reconnectable?: boolean;
        testId: string;
    }>(),
    { meta: null, media: () => [], disabled: false, reconnectable: true },
);

const emit = defineEmits<{ reconnect: [account: ComposerAccount] }>();

const { canManageAccounts } = useWorkspaceAbilities();

const permissionWarning = computed(() => {
    for (const account of props.accounts) {
        const key = channelPermissionKey(
            props.platformConfigs[account.id],
            props.contentType,
            props.meta,
            canManageAccounts.value,
            mediaHasVideoCover(props.media),
        );

        if (filled(key)) {
            return { account, key };
        }
    }

    return null;
});

const networkLabel = computed((): string => {
    if (!filled(permissionWarning.value)) {
        return '';
    }

    return getPlatformLabel(permissionWarning.value.account.platform);
});

/** A card of several channels names the one the warning is about. */
const namedChannel = computed((): string | null => {
    if (props.accounts.length < 2) {
        return null;
    }

    if (!filled(permissionWarning.value)) {
        return null;
    }

    return channelName(permissionWarning.value.account);
});

const offersReconnect = computed((): boolean => {
    if (!props.reconnectable) {
        return false;
    }

    return canManageAccounts.value;
});

const reconnect = (): void => {
    if (filled(permissionWarning.value)) {
        emit('reconnect', permissionWarning.value.account);
    }
};
</script>

<template>
    <WarningLine v-if="permissionWarning" :data-testid="testId">
        <TransSlots
            v-if="namedChannel"
            :message="$t('channels.permissions.on_channel')"
        >
            <template #channel>
                <strong>{{ namedChannel }}</strong>
            </template>
            <template #message>
                {{ $t(permissionWarning.key, { network: networkLabel }) }}
            </template>
        </TransSlots>
        <template v-else>
            {{ $t(permissionWarning.key, { network: networkLabel }) }}
        </template>
        {{ ' ' }}
        <WarningLink
            v-if="offersReconnect"
            :data-testid="`${testId}-reconnect`"
            :disabled="disabled"
            @click="reconnect"
        >
            {{ $t('posts.form.reconnect.save') }}
        </WarningLink>
    </WarningLine>
</template>
