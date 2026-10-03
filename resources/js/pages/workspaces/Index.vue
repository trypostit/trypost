<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconCheck, IconPlus } from '@tabler/icons-vue';
import { ref } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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

const handleCreateWorkspace = (): void => {
    createOrUpgrade(() => {
        upgradeDialogOpen.value = true;
    });
};
</script>

<template>
    <Head :title="$t('workspaces.title')" />

    <AuthLayout
        :title="$t('workspaces.select_title')"
        :description="$t('workspaces.select_description')"
        width="md"
    >
        <ul class="flex flex-col gap-2" data-testid="workspaces-list">
            <li v-for="workspace in workspaces" :key="workspace.id">
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-3 rounded-xl border border-border bg-card p-4 text-left transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :aria-current="
                        workspace.id === currentWorkspaceId ? 'true' : undefined
                    "
                    :data-testid="`workspaces-item-${workspace.id}`"
                    @click="switchToWorkspace(workspace)"
                >
                    <Avatar
                        :src="workspace.logo_url"
                        :name="workspace.name"
                        class="size-10 shrink-0 rounded-lg"
                        fallback-class="rounded-lg bg-muted text-muted-foreground"
                    />
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm leading-tight font-emphasis text-foreground"
                            >{{ workspace.name }}</span
                        >
                        <span
                            class="mt-1 block truncate text-sm text-muted-foreground"
                        >
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
                    <Badge
                        v-if="workspace.id === currentWorkspaceId"
                        variant="success"
                        class="h-6 shrink-0 gap-1 px-2 [&>svg]:size-4"
                    >
                        <IconCheck aria-hidden="true" />
                        {{ $t('workspaces.current') }}
                    </Badge>
                </button>
            </li>
        </ul>

        <Button
            v-if="canCreateWorkspace"
            variant="outline"
            size="lg"
            class="w-full bg-card text-base"
            data-testid="workspaces-create"
            @click="handleCreateWorkspace"
        >
            <IconPlus aria-hidden="true" />
            {{ $t('workspaces.create.submit') }}
        </Button>

        <WorkspaceUpgradeDialog v-model:open="upgradeDialogOpen" />
    </AuthLayout>
</template>
