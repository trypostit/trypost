<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconCheck, IconPlus } from '@tabler/icons-vue';
import { ref } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import WorkspaceUpgradeDialog from '@/components/workspaces/WorkspaceUpgradeDialog.vue';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { useWorkspaceLimit } from '@/composables/useWorkspaceLimit';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { switchMethod } from '@/routes/app/workspaces';

interface Workspace {
    id: string;
    name: string;
    logo_url: string | null;
    social_accounts_count: number;
    posts_count: number;
}

interface Props {
    workspaces: Workspace[];
    currentWorkspaceId: string | null;
}

const props = defineProps<Props>();

const { canCreateWorkspace } = useWorkspaceAbilities();
const { createOrUpgrade } = useWorkspaceLimit(() => props.workspaces.length);

const upgradeDialogOpen = ref(false);

const switchToWorkspace = (workspace: Workspace): void => {
    router.post(
        switchMethod.url(workspace.id),
        {},
        {
            preserveState: false,
        },
    );
};

const openUpgradeDialog = (): void => {
    upgradeDialogOpen.value = true;
};

const handleCreateWorkspace = (): void => {
    createOrUpgrade(openUpgradeDialog);
};
</script>

<template>
    <Head :title="$t('workspaces.title')" />

    <AuthLayout
        :title="$t('workspaces.select_title')"
        :description="$t('workspaces.select_description')"
    >
        <div class="flex flex-col gap-4">
            <ul
                class="flex flex-col divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
                data-testid="workspaces-list"
            >
                <li v-for="workspace in workspaces" :key="workspace.id">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left transition-control hover:bg-accent focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                        :aria-current="
                            workspace.id === currentWorkspaceId
                                ? 'true'
                                : undefined
                        "
                        :data-testid="`workspaces-item-${workspace.id}`"
                        @click="switchToWorkspace(workspace)"
                    >
                        <Avatar
                            :src="workspace.logo_url"
                            :name="workspace.name"
                            class="size-9 shrink-0 rounded-lg"
                            fallback-class="text-sm bg-primary-subtle text-primary-text font-bold"
                        />
                        <span class="grid min-w-0 flex-1 gap-0.5">
                            <span
                                class="truncate text-sm leading-5 font-semibold text-foreground"
                                >{{ workspace.name }}</span
                            >
                            <span class="truncate text-xs text-muted-foreground">
                                {{
                                    $t('workspaces.connections', {
                                        count: String(
                                            workspace.social_accounts_count,
                                        ),
                                    })
                                }}
                                ·
                                {{
                                    $t('workspaces.posts', {
                                        count: String(workspace.posts_count),
                                    })
                                }}
                            </span>
                        </span>
                        <IconCheck
                            v-if="workspace.id === currentWorkspaceId"
                            class="size-4 shrink-0 text-foreground"
                            stroke-width="2.5"
                            :aria-label="$t('workspaces.current')"
                            :data-testid="`workspaces-current-${workspace.id}`"
                        />
                    </button>
                </li>
            </ul>

            <Button
                v-if="canCreateWorkspace"
                variant="outline"
                class="w-full bg-card"
                data-testid="workspaces-create"
                @click="handleCreateWorkspace"
            >
                <IconPlus aria-hidden="true" />
                {{ $t('workspaces.create.submit') }}
            </Button>
        </div>

        <WorkspaceUpgradeDialog v-model:open="upgradeDialogOpen" />
    </AuthLayout>
</template>
