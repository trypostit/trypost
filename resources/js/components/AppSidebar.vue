<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconBulb,
    IconCalendarEvent,
    IconTrendingUp,
    IconFileText,
    IconLayoutGrid,
    IconPlus,
    IconRepeat,
    IconUsers,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import { index as postsIndex } from '@/actions/App/Http/Controllers/App/PostController';
import InviteMemberDialog from '@/components/members/InviteMemberDialog.vue';
import NavChannels from '@/components/NavChannels.vue';
import NavMain from '@/components/NavMain.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    useSidebar,
} from '@/components/ui/sidebar';
import WorkspaceMenuContent from '@/components/WorkspaceMenuContent.vue';
import WorkspaceUpgradeDialog from '@/components/workspaces/WorkspaceUpgradeDialog.vue';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { insights } from '@/routes/app';
import { portal } from '@/routes/app/billing';
import { index as ideasIndex, create as createIdea } from '@/routes/app/create/ideas';
import { index as repurposes } from '@/routes/app/repurposes';
import type { NavItem, User } from '@/types';
import type { SidebarChannel } from '@/types/channel';

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

const {
    canCreatePost,
    canManageRepurposes,
    canCreateWorkspace,
    canManageAccounts,
    canManageTeam,
} = useWorkspaceAbilities();
const { open: openConnectDialog } = useConnectChannelDialog();

const inviteMemberDialogOpen = ref(false);

const openInviteMemberDialog = (): void => {
    inviteMemberDialogOpen.value = true;
};

const { isMobile, state: sidebarState } = useSidebar();

const workspaceUpgradeDialogOpen = ref(false);

const openWorkspaceUpgradeDialog = (): void => {
    workspaceUpgradeDialogOpen.value = true;
};

const scheduledPostsCount = computed(() =>
    ((page.props.channels as SidebarChannel[] | undefined) ?? []).reduce(
        (total, channel) => total + channel.scheduled_posts_count,
        0,
    ),
);

const mainNavItems = computed<NavItem[]>(() => [
    ...(canCreatePost.value
        ? [
              {
                  title: trans('sidebar.create'),
                  href: ideasIndex.url(),
                  icon: IconBulb,
                  activePattern: '/create',
              },
          ]
        : []),
    {
        title: trans('sidebar.groups.posts'),
        href: postsIndex.url(),
        icon: IconCalendarEvent,
        count: scheduledPostsCount.value,
        countTestId: 'sidebar-publish-count',
    },
    {
        title: trans('channels.insights'),
        href: insights.url(),
        icon: IconTrendingUp,
    },
    ...(canManageRepurposes.value
        ? [
              {
                  title: trans('sidebar.repurposes'),
                  href: repurposes.url(),
                  icon: IconRepeat,
                  badge: trans('common.beta'),
              },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarHeader
            class="flex-row items-center justify-between gap-3 px-4 pt-4 pb-0 group-data-[collapsible=icon]:px-2.5"
        >
            <Link
                :href="postsIndex.url()"
                class="flex h-8 items-center rounded-md px-2 outline-hidden focus-visible:ring-2 focus-visible:ring-sidebar-ring group-data-[collapsible=icon]:px-1"
                data-testid="sidebar-logo"
            >
                <img
                    src="/images/trypost/logo-light.png"
                    alt="TryPost"
                    class="h-[19px] w-auto group-data-[collapsible=icon]:hidden dark:hidden"
                />
                <img
                    src="/images/trypost/logo-dark.png"
                    alt="TryPost"
                    class="hidden h-[19px] w-auto dark:block dark:group-data-[collapsible=icon]:hidden"
                />
                <img
                    src="/images/trypost/icon.png"
                    alt="TryPost"
                    class="hidden size-6 group-data-[collapsible=icon]:block"
                />
            </Link>
        </SidebarHeader>

        <SidebarContent class="gap-0">
            <div
                v-if="currentWorkspace && canCreatePost"
                class="px-4 pt-4 pb-3 group-data-[collapsible=icon]:px-2.5"
            >
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            class="h-9 w-full justify-center gap-1.5 rounded-full bg-primary-strong font-medium text-primary-strong-foreground hover:bg-primary-text-hover data-[state=open]:bg-primary-text-hover group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:rounded-lg group-data-[collapsible=icon]:px-0"
                            :aria-label="$t('sidebar.new')"
                            data-testid="sidebar-new"
                        >
                            <IconPlus
                                class="hidden size-4 group-data-[collapsible=icon]:block"
                            />
                            <span class="group-data-[collapsible=icon]:hidden">{{
                                $t('sidebar.new')
                            }}</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="w-72 p-1.5"
                        align="start"
                        :side="
                            isMobile || sidebarState === 'expanded'
                                ? 'bottom'
                                : 'right'
                        "
                        :side-offset="4"
                        data-testid="sidebar-new-menu"
                    >
                        <DropdownMenuItem
                            class="gap-3 p-2"
                            data-testid="sidebar-new-post"
                            @select="openPostComposer()"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-text-hover"
                            >
                                <IconFileText class="size-5 text-primary-text-hover" />
                            </span>
                            <span class="grid gap-0.5">
                                <span class="text-sm font-semibold">{{
                                    $t('sidebar.new_menu.post')
                                }}</span>
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                    >{{
                                        $t('sidebar.new_menu.post_description')
                                    }}</span
                                >
                            </span>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="gap-3 p-2"
                            data-testid="sidebar-new-idea"
                            @select="router.visit(createIdea.url())"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary-strong"
                            >
                                <IconBulb class="size-5 text-primary-strong" />
                            </span>
                            <span class="grid gap-0.5">
                                <span class="text-sm font-semibold">{{
                                    $t('sidebar.new_menu.idea')
                                }}</span>
                                <span
                                    class="text-xs font-normal text-muted-foreground"
                                    >{{
                                        $t('sidebar.new_menu.idea_description')
                                    }}</span
                                >
                            </span>
                        </DropdownMenuItem>
                        <template v-if="canManageAccounts || canManageTeam">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                v-if="canManageAccounts"
                                data-testid="sidebar-new-channel"
                                @select="openConnectDialog()"
                            >
                                <IconLayoutGrid
                                    class="size-4 text-muted-foreground"
                                />
                                {{ $t('sidebar.new_menu.channel') }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="canManageTeam"
                                data-testid="sidebar-new-member"
                                @select="openInviteMemberDialog"
                            >
                                <IconUsers class="size-4 text-muted-foreground" />
                                {{ $t('sidebar.new_menu.member') }}
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <NavMain v-if="currentWorkspace" :items="mainNavItems" />
            <NavChannels v-if="currentWorkspace" />
        </SidebarContent>
        <SidebarFooter class="gap-0 p-0">
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
                            @upgrade-required="openWorkspaceUpgradeDialog"
                        />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </SidebarFooter>

        <WorkspaceUpgradeDialog v-model:open="workspaceUpgradeDialogOpen" />
        <InviteMemberDialog
            v-if="canManageTeam"
            v-model:open="inviteMemberDialogOpen"
        />
    </Sidebar>
</template>
