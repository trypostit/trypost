<script setup lang="ts">
import { computed } from 'vue';

import WarningLine from '@/components/WarningLine.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { ComposerAccount } from '@/composables/usePostComposition';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import {
    missingPermissionKey,
    missingPermissionsOf,
} from '@/lib/channelPermissions';
import { filled } from '@/lib/helpers';

/**
 * Why a card cannot go out: one of its channels lacks the scope a feature
 * the post uses needs (`missingPermissions` of its platform config). It names
 * the first such channel; someone who manages channels gets a link that saves
 * the post and reconnects that channel, anyone else is told who can.
 */
const props = withDefaults(
    defineProps<{
        accounts: ComposerAccount[];
        platformConfigs: Record<string, any>;
        meta?: Record<string, any> | null;
        disabled?: boolean;
    }>(),
    { meta: null, disabled: false },
);

const emit = defineEmits<{ reconnect: [account: ComposerAccount] }>();

const { canManageAccounts } = useWorkspaceAbilities();

const keyFor = (account: ComposerAccount): string | null =>
    missingPermissionKey(
        props.meta,
        missingPermissionsOf(props.platformConfigs[account.id]),
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
const channelName = computed((): string | null => {
    if (props.accounts.length < 2 || !filled(blockedAccount.value)) {
        return null;
    }

    if (filled(blockedAccount.value.display_name)) {
        return blockedAccount.value.display_name;
    }

    return blockedAccount.value.username;
});

const reconnect = (): void => {
    if (filled(blockedAccount.value)) {
        emit('reconnect', blockedAccount.value);
    }
};
</script>

<template>
    <WarningLine
        v-if="warningKey"
        data-testid="channel-permission-warning"
    >
        <strong v-if="channelName">{{ channelName }}: </strong>
        {{ $t(warningKey, { network: networkLabel }) }}
        <button
            v-if="canManageAccounts"
            type="button"
            class="font-medium underline underline-offset-2 hover:text-foreground/80 disabled:pointer-events-none disabled:opacity-50"
            data-testid="channel-permission-reconnect"
            :disabled="disabled"
            @click="reconnect"
        >
            {{ $t('posts.form.reconnect.save') }}</button
        >
    </WarningLine>
</template>
