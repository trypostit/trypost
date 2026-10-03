<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { ref } from 'vue';

import UsersTab from '@/components/settings/UsersTab.vue';
import { Button } from '@/components/ui/button';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import type { WorkspaceInvitation, WorkspaceMember } from '@/types/members';

defineProps<{
    workspace: { id: string; name: string };
    owner: { id: string | null; name: string | null; email: string | null };
    members: WorkspaceMember[];
    invites: WorkspaceInvitation[];
}>();

const { canManageTeam } = useWorkspaceAbilities();
const inviteOpen = ref(false);

const openInviteDialog = (): void => {
    inviteOpen.value = true;
};

</script>

<template>
    <Head :title="$t('settings.members.title')" />

    <SettingsLayout
        :title="$t('settings.members.title')"
        :description="$t('settings.workspace.members_description')"
    >
        <template v-if="canManageTeam" #actions>
            <Button data-testid="invite-member-button" @click="openInviteDialog">
                <IconPlus class="size-4" />
                {{ $t('settings.members.invite.submit') }}
            </Button>
        </template>

        <UsersTab
            v-model:invite-open="inviteOpen"
            :members="members"
            :invitations="invites"
            :owner-id="owner.id"
        />
    </SettingsLayout>
</template>
