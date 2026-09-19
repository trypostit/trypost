<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import WorkspaceUpgradeDialog from '@/components/workspaces/WorkspaceUpgradeDialog.vue';
import { useWorkspaceLimit } from '@/composables/useWorkspaceLimit';
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
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

const { canCreateWorkspace } = useWorkspaceRole();
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
    >
        <div class="space-y-3">
            <div
                v-for="workspace in workspaces"
                :key="workspace.id"
                class="flex cursor-pointer items-center gap-3 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                :class="
                    workspace.id === currentWorkspaceId ? 'bg-violet-100' : ''
                "
                @click="switchToWorkspace(workspace)"
            >
                <Avatar
                    :src="workspace.logo_url"
                    :name="workspace.name"
                    class="size-10 shrink-0 rounded-lg border-2 border-foreground"
                    fallback-class="bg-muted text-muted-foreground"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ workspace.name }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            trans('workspaces.connections', {
                                count: String(workspace.social_accounts_count),
                            })
                        }}
                        ·
                        {{
                            trans('workspaces.posts', {
                                count: String(workspace.posts_count),
                            })
                        }}
                    </p>
                </div>
                <Badge
                    v-if="workspace.id === currentWorkspaceId"
                    variant="secondary"
                    class="shrink-0"
                >
                    {{ $t('workspaces.current') }}
                </Badge>
            </div>
        </div>

        <Button
            v-if="canCreateWorkspace"
            variant="outline"
            class="w-full"
            data-testid="workspaces-create"
            @click="handleCreateWorkspace"
        >
            {{ $t('workspaces.create.submit') }}
        </Button>

        <WorkspaceUpgradeDialog v-model:open="upgradeDialogOpen" />
    </AuthLayout>
</template>
