<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import SidebarCollapseToggle from '@/components/SidebarCollapseToggle.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import WorkspaceMenuContent from '@/components/WorkspaceMenuContent.vue';
import WorkspaceUpgradeDialog from '@/components/workspaces/WorkspaceUpgradeDialog.vue';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { useWorkspaceLimit } from '@/composables/useWorkspaceLimit';
import { portal } from '@/routes/app/billing';
import type { User } from '@/types';

interface Workspace {
    id: string;
    name: string;
    logo_url: string | null;
}

const page = usePage();
const user = computed(() => page.props.auth.user as User);
const currentWorkspace = computed<Workspace | null>(
    () => page.props.auth.currentWorkspace as Workspace | null,
);
const workspaces = computed<Workspace[]>(
    () => page.props.auth.workspaces as Workspace[],
);
const subscriptionPastDue = computed<boolean>(() =>
    Boolean(page.props.auth.subscriptionPastDue),
);

const { canCreateWorkspace } = useWorkspaceAbilities();
const { createOrUpgrade } = useWorkspaceLimit();

const workspaceUpgradeDialogOpen = ref(false);

const openWorkspaceUpgradeDialog = (): void => {
    workspaceUpgradeDialogOpen.value = true;
};

const handleCreateWorkspace = (): void => {
    createOrUpgrade(openWorkspaceUpgradeDialog);
};
</script>

<template>
    <div>
        <div
            v-if="subscriptionPastDue"
            class="mx-4 mb-2 rounded-xl border border-destructive bg-destructive/10 p-3 group-data-[collapsible=icon]:hidden"
        >
            <div class="flex items-center gap-2 text-destructive">
                <IconAlertTriangle class="size-4 shrink-0" />
                <span class="text-sm font-medium">{{
                    $t('billing.past_due_notice.title')
                }}</span>
            </div>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ $t('billing.past_due_notice.description') }}
            </p>
            <Button
                as="a"
                :href="portal.url()"
                variant="destructive"
                size="sm"
                class="mt-2 w-full"
            >
                {{ $t('billing.past_due_notice.cta') }}
            </Button>
        </div>
        <div
            class="flex items-center gap-2 border-t border-sidebar-border px-4 py-2.5 group-data-[collapsible=icon]:flex-col-reverse group-data-[collapsible=icon]:gap-1 group-data-[collapsible=icon]:px-2.5"
        >
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="-ms-1.5 flex min-w-0 flex-1 cursor-pointer items-center gap-2 rounded-lg py-1 ps-2 pe-0 text-start outline-hidden transition-control hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring data-[state=open]:bg-sidebar-accent group-data-[collapsible=icon]:ms-0 group-data-[collapsible=icon]:flex-none group-data-[collapsible=icon]:py-0 group-data-[collapsible=icon]:ps-0"
                        data-test="sidebar-menu-button"
                        data-testid="sidebar-workspace-menu"
                    >
                        <Avatar
                            :src="user.photo_url"
                            :name="user.name"
                            class="size-8 shrink-0 rounded-lg"
                            fallback-class="bg-primary-subtle text-primary-text text-xs font-medium"
                        />
                        <span
                            class="grid min-w-0 flex-1 group-data-[collapsible=icon]:hidden"
                        >
                            <span
                                class="truncate text-sm font-medium text-sidebar-foreground"
                            >
                                {{ user.name }}
                            </span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{
                                    currentWorkspace?.name ??
                                    $t('sidebar.select_workspace')
                                }}
                            </span>
                        </span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-64"
                    align="start"
                    side="top"
                    :side-offset="4"
                >
                    <WorkspaceMenuContent
                        :user="user"
                        :current-workspace="currentWorkspace"
                        :workspaces="workspaces"
                        :can-create-workspace="canCreateWorkspace"
                        @create-workspace="handleCreateWorkspace"
                    />
                </DropdownMenuContent>
            </DropdownMenu>
            <SidebarCollapseToggle />
        </div>
        <WorkspaceUpgradeDialog v-model:open="workspaceUpgradeDialogOpen" />
    </div>
</template>
