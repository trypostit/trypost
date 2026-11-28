<script setup lang="ts">
import { computed } from 'vue';

import WarningLine from '@/components/app/WarningLine.vue';
import WarningLink from '@/components/app/WarningLink.vue';
import { useNetworkConnect } from '@/composables/app/useNetworkConnect';
import { useWorkspaceAbilities } from '@/composables/app/useWorkspaceAbilities';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { permissionMessageKey } from '@/lib/app/channelPermissions';
import { filled } from '@/lib/helpers';
import type { ConnectedAccount } from '@/types/app/social-account';
import type {
    GatedFeatureValue,
    MissingPermission,
} from '@/types/channel-permission';

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

        if (filled(missing)) {
            return missing;
        }
    }

    return null;
});

const messageKey = computed((): string | null => {
    if (!filled(permission.value)) {
        return null;
    }

    return permissionMessageKey(permission.value, canManageAccounts.value);
});

const networkLabel = computed(() => getPlatformLabel(props.channel.platform));

const reconnect = (): void => {
    startConnect(props.channel.platform, props.channel.id);
};
</script>

<template>
    <WarningLine v-if="messageKey" data-testid="channel-permission-notice">
        {{ $t(messageKey, { network: networkLabel }) }}
        <WarningLink
            v-if="canManageAccounts"
            data-testid="channel-permission-notice-reconnect"
            @click="reconnect"
        >
            {{ $t('channels.reconnect') }}
        </WarningLink>
    </WarningLine>
</template>
