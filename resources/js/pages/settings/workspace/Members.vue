<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import PageHeader from '@/components/PageHeader.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import UsersTab from '@/components/settings/UsersTab.vue';
import { useWorkspaceSettingsTabs } from '@/composables/useWorkspaceSettingsTabs';
import AppLayout from '@/layouts/AppLayout.vue';

interface Workspace {
    id: string;
    name: string;
}

interface Member {
    id: string;
    name: string;
    email: string;
    role: string;
}

interface Invite {
    id: string;
    email: string;
    role: string;
}

interface Role {
    value: string;
    label: string;
}

defineProps<{
    workspace: Workspace;
    owner: Member;
    members: Member[];
    invites: Invite[];
    roles: Role[];
}>();

const tabs = useWorkspaceSettingsTabs();
</script>

<template>
    <Head :title="$t('settings.members.title')" />

    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <PageHeader
                :title="$t('settings.hub.title')"
                :description="$t('settings.hub.description')"
            />

            <SettingsTabsNav :tabs="tabs" active="members" />

            <UsersTab
                :members="members"
                :invitations="invites"
                :roles="roles"
            />
        </div>
    </AppLayout>
</template>
