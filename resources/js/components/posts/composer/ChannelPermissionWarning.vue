<script setup lang="ts">
import { computed } from 'vue';

import TransSlots from '@/components/TransSlots.vue';
import WarningLine from '@/components/WarningLine.vue';
import WarningLink from '@/components/WarningLink.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { ComposerAccount } from '@/composables/usePostComposition';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { channelPermissionKey } from '@/lib/channelPermissions';
import { filled } from '@/lib/helpers';
import { channelName } from '@/types/channel';

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
        platformConfigs: Record<string, any>;
        meta?: Record<string, any> | null;
        disabled?: boolean;
        reconnectable?: boolean;
        testId: string;
    }>(),
    { meta: null, disabled: false, reconnectable: true },
);

const emit = defineEmits<{ reconnect: [account: ComposerAccount] }>();

const { canManageAccounts } = useWorkspaceAbilities();

const keyFor = (account: ComposerAccount): string | null =>
    channelPermissionKey(
        props.platformConfigs[account.id],
        props.meta,
        canManageAccounts.value,
    );

const blockedAccount = computed((): ComposerAccount | null => {
    for (const account of props.accounts) {
        if (filled(keyFor(account))) {
            return account;
        }
    }

    return null;
});

const warningKey = computed((): string | null => {
    if (!filled(blockedAccount.value)) {
        return null;
    }

    return keyFor(blockedAccount.value);
});

const networkLabel = computed((): string => {
    if (!filled(blockedAccount.value)) {
        return '';
    }

    return getPlatformLabel(blockedAccount.value.platform);
});

/** A card of several channels names the one the warning is about. */
const namedChannel = computed((): string | null => {
    if (props.accounts.length < 2) {
        return null;
    }

    if (!filled(blockedAccount.value)) {
        return null;
    }

    return channelName(blockedAccount.value);
});

const offersReconnect = computed((): boolean => {
    if (!props.reconnectable) {
        return false;
    }

    return canManageAccounts.value;
});

const reconnect = (): void => {
    if (filled(blockedAccount.value)) {
        emit('reconnect', blockedAccount.value);
    }
};
</script>

<template>
    <WarningLine v-if="warningKey" :data-testid="testId">
        <TransSlots
            v-if="namedChannel"
            :message="$t('channels.permissions.on_channel')"
        >
            <template #channel>
                <strong>{{ namedChannel }}</strong>
            </template>
            <template #message>
                {{ $t(warningKey, { network: networkLabel }) }}
            </template>
        </TransSlots>
        <template v-else>
            {{ $t(warningKey, { network: networkLabel }) }}
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
