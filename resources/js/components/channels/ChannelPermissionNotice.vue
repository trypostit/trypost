<script setup lang="ts">
import { computed } from 'vue';

import WarningLine from '@/components/WarningLine.vue';
import { useNetworkConnect } from '@/composables/useNetworkConnect';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import type { GatedFeatureValue, MissingPermission } from '@/lib/channelPermissions';
import type { ConnectedAccount } from '@/types/social-account';

/**
 * Says a channel lacks the scope one of `features` needs (its
 * `missing_permissions`), outside the composer: someone who manages channels
 * gets a link that reconnects it, anyone else is told who can.
 */
const props = defineProps<{
    channel: Pick<ConnectedAccount, 'id' | 'platform' | 'missing_permissions'>;
    features: GatedFeatureValue[];
}>();

const { canManageAccounts } = useWorkspaceAbilities();
const { startConnect } = useNetworkConnect([]);

const permission = computed((): MissingPermission | null => {
    for (const feature of props.features) {
        const missing = props.channel.missing_permissions?.[feature];

        if (missing !== undefined) {
            return missing;
        }
    }

    return null;
});

const messageKey = computed((): string | null => {
    if (permission.value === null) {
        return null;
    }

    if (canManageAccounts.value) {
        return permission.value.message;
    }

    return permission.value.member_message;
});

const networkLabel = computed(() => getPlatformLabel(props.channel.platform));

const reconnect = (): void => {
    startConnect(props.channel.platform, props.channel.id);
};
</script>

<template>
    <WarningLine v-if="messageKey" data-testid="channel-permission-notice">
        {{ $t(messageKey, { network: networkLabel }) }}
        <button
            v-if="canManageAccounts"
            type="button"
            class="font-medium underline underline-offset-2 hover:text-foreground/80"
            data-testid="channel-permission-notice-reconnect"
            @click="reconnect"
        >
            {{ $t('channels.reconnect') }}</button
        >
    </WarningLine>
</template>
