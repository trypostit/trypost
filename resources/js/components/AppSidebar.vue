<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconBulb,
    IconCalendarEvent,
    IconTrendingUp,
    IconFileText,
    IconMessages,
    IconLayoutGrid,
    IconPlus,
    IconRepeat,
    IconUsers,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import { index as postsIndex } from '@/actions/App/Http/Controllers/App/PostController';
import AppLogo from '@/components/AppLogo.vue';
import ChangelogCard from '@/components/changelog/ChangelogCard.vue';
import InviteMemberDialog from '@/components/members/InviteMemberDialog.vue';
import NavChannels from '@/components/NavChannels.vue';
import NavMain from '@/components/NavMain.vue';
import SidebarUserMenu from '@/components/SidebarUserMenu.vue';
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
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { insights } from '@/routes/app';
import { index as ideasIndex, create as createIdea } from '@/routes/app/create/ideas';
import { index as inboxIndex } from '@/routes/app/inbox';
import { index as repurposes } from '@/routes/app/repurposes';
import type { NavItem } from '@/types';
import type { SidebarChannel } from '@/types/channel';
import type { InboxCounts } from '@/types/inbox';

const page = usePage();
const currentWorkspace = computed(
    () => page.props.auth.currentWorkspace,
);

const {
    canCreatePost,
    canManageRepurposes,
    canManageAccounts,
    canManageTeam,
} = useWorkspaceAbilities();
const { open: openConnectDialog } = useConnectChannelDialog();

const inviteMemberDialogOpen = ref(false);

const openInviteMemberDialog = (): void => {
    inviteMemberDialogOpen.value = true;
};

const { isMobile, state: sidebarState } = useSidebar();

const scheduledPostsCount = computed(() =>
    ((page.props.channels as SidebarChannel[] | undefined) ?? []).reduce(
        (total, channel) => total + channel.scheduled_posts_count,
        0,
    ),
);

const inboxCounts = computed(
    () => page.props.inbox as InboxCounts | undefined,
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
    ...(inboxCounts.value
        ? [
              {
                  title: trans('sidebar.inbox'),
                  href: inboxIndex.url(),
                  icon: IconMessages,
                  count: inboxCounts.value.total,
                  countTestId: 'sidebar-inbox-count',
                  countHighlighted: true,
              },
          ]
        : []),
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
                <span class="inline-flex group-data-[collapsible=icon]:hidden">
                    <AppLogo class="text-[20px]" />
                </span>
                <span class="hidden group-data-[collapsible=icon]:inline-flex">
                    <AppLogo variant="mark" class="text-[21px]" />
                </span>
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
                            class="h-9 w-full justify-center gap-1.5 rounded-full bg-primary-strong font-medium text-primary-strong-foreground hover:bg-primary-text-hover data-[state=open]:bg-primary-text-hover dark:hover:bg-primary dark:data-[state=open]:bg-primary group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:rounded-lg group-data-[collapsible=icon]:px-0"
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
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-text-hover dark:bg-primary-strong dark:text-primary-strong-foreground"
                                data-testid="sidebar-new-post-icon"
                            >
                                <IconFileText
                                    class="size-5 text-primary-text-hover dark:text-primary-strong-foreground"
                                />
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
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary-strong dark:text-primary-text"
                                data-testid="sidebar-new-idea-icon"
                            >
                                <IconBulb
                                    class="size-5 text-primary-strong dark:text-primary-text"
                                />
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
            <ChangelogCard />
            <SidebarUserMenu />
        </SidebarFooter>

        <InviteMemberDialog
            v-if="canManageTeam"
            v-model:open="inviteMemberDialogOpen"
        />
    </Sidebar>
</template>
