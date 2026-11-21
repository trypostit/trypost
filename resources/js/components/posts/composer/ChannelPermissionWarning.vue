<script setup lang="ts">
import { computed } from 'vue';

import ComposerCardWarning from '@/components/posts/composer/ComposerCardWarning.vue';
import type { ComposerAccount } from '@/composables/usePostComposition';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { missingPermissionKey } from '@/lib/channelPermissions';
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
        props.platformConfigs[account.id]?.missingPermissions ?? null,
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
    if (blockedAccount.value === null) {
        return null;
    }

    return keyFor(blockedAccount.value);
});

const reconnect = (): void => {
    if (blockedAccount.value !== null) {
        emit('reconnect', blockedAccount.value);
    }
};
</script>

<template>
    <ComposerCardWarning
        v-if="warningKey"
        data-testid="channel-permission-warning"
    >
        {{ $t(warningKey) }}
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
    </ComposerCardWarning>
</template>
