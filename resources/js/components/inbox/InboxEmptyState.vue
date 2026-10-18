<script setup lang="ts">
import { IconMessages, IconPlus } from '@tabler/icons-vue';

import EmptyState from '@/components/EmptyState.vue';
import InboxEmptyIllustration from '@/components/inbox/InboxEmptyIllustration.vue';
import { Button } from '@/components/ui/button';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';

withDefaults(
    defineProps<{
        filtered: boolean;
        mentions?: boolean;
        messages?: boolean;
    }>(),
    { mentions: false, messages: false },
);

const { canManageAccounts } = useWorkspaceAbilities();
const { open: openConnectDialog } = useConnectChannelDialog();

const connectChannel = (): void => {
    openConnectDialog();
};
</script>

<template>
    <EmptyState
        v-if="filtered"
        :icon="IconMessages"
        :title="
            messages
                ? $t('inbox.direct.filtered_title')
                : mentions
                  ? $t('inbox.mentions.filtered_title')
                  : $t('inbox.empty.filtered_title')
        "
        :description="$t('inbox.empty.filtered_description')"
        data-testid="inbox-empty-filtered"
    />
    <EmptyState
        v-else
        :title="$t('inbox.empty.title')"
        :description="
            canManageAccounts
                ? $t('inbox.empty.description')
                : $t('inbox.empty.member_description')
        "
        data-testid="inbox-empty"
    >
        <template #illustration>
            <InboxEmptyIllustration />
        </template>
        <template v-if="canManageAccounts" #action>
            <Button data-testid="inbox-empty-connect" @click="connectChannel">
                <IconPlus aria-hidden="true" />
                {{ $t('inbox.empty.connect') }}
            </Button>
        </template>
    </EmptyState>
</template>
