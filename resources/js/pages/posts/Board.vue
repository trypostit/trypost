<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { IconPlus, IconUsers } from '@tabler/icons-vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, onUnmounted, provide, ref, watch } from 'vue';

import { destroy as destroyPost } from '@/actions/App/Http/Controllers/App/PostController';
import AppHeaderActions from '@/components/AppHeaderActions.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import InviteMemberDialog from '@/components/members/InviteMemberDialog.vue';
import PostChannelFilter from '@/components/posts/PostChannelFilter.vue';
import ScheduleViewSwitch from '@/components/posts/ScheduleViewSwitch.vue';
import BoardColumn from '@/components/publish/board/BoardColumn.vue';
import BoardGroupMenu from '@/components/publish/board/BoardGroupMenu.vue';
import NewPostButton from '@/components/publish/NewPostButton.vue';
import PublishEmptyIllustration from '@/components/publish/PublishEmptyIllustration.vue';
import PublishFilterMenu from '@/components/publish/PublishFilterMenu.vue';
import PublishHeader from '@/components/publish/PublishHeader.vue';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import { usePublishFilters } from '@/composables/publish/usePublishFilters';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import {
    deletePostCardKey,
    postCardLabelsKey,
    type PostCardReload,
    postCardReloadKey,
} from '@/composables/usePostCardActions';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import AppLayout from '@/layouts/AppLayout.vue';
import { blank, filled } from '@/lib/helpers';
import { grid } from '@/routes/app/channels';
import type { BoardColumnMeta, BoardGroup } from '@/types/board';
import { channelName } from '@/types/channel';
import type { TimezoneOption } from '@/types/posting-schedule';
import type {
    PostCard,
    PostCardLabel,
    PublishChannel,
    PublishScope,
    PublishSocialAccount,
} from '@/types/publish';

interface Props {
    scope: PublishScope;
    channel: PublishChannel | null;
    group: BoardGroup;
    groups: BoardGroup[];
    boardColumns: BoardColumnMeta[];
    displayTimezone: string;
    timezones: TimezoneOption[];
    labels: PostCardLabel[];
    filters: {
        labels: string[];
        untagged: boolean;
        channels: string[];
    };
    filterAccounts: PublishSocialAccount[];
    hasChannels: boolean;
}

const props = defineProps<Props>();
const page = usePage();
const { canManageAccounts, canManageTeam } = useWorkspaceAbilities();

const {
    timezone,
    setTimezone,
    selectedLabelIds,
    selectedUntagged,
    selectedChannelIds,
    filterQuery,
    boardUrlFor,
    listHref,
    calendarHref,
} = usePublishFilters(props, { reloadProps: () => ['displayTimezone'] });

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

/** Deleting a card reloads the columns only, like the card's other actions, so the board keeps its loaded pages. */
provide(deletePostCardKey, (post: PostCard) => {
    deleteModal.value?.open({
        url: destroyPost.url(post.id),
        visit: columnsReload(),
    });
});

provide(
    postCardLabelsKey,
    computed(() => props.labels),
);

const selectedGroup = ref<BoardGroup>(props.group);

/** The group in the URL; Status is the default and stays out of it. */
const groupQuery = (): BoardGroup | undefined => {
    if (selectedGroup.value === 'status') {
        return undefined;
    }

    return selectedGroup.value;
};

const boardUrl = (): string =>
    boardUrlFor({ ...filterQuery(), group: groupQuery() });

const columnPaths = (): string[] =>
    props.boardColumns.map((column) => `columns.${column.key}`);

/** A new group or filter rebuilds the columns from their first page. */
const applyBoard = (): void => {
    router.get(
        boardUrl(),
        {},
        {
            preserveState: true,
            preserveScroll: false,
            replace: true,
        },
    );
};

watch(
    [selectedGroup, selectedLabelIds, selectedUntagged, selectedChannelIds],
    applyBoard,
    { deep: true },
);

/** The board's columns reload with the list of columns, so a column that is gone leaves and a new one shows. */
const columnsReload = (): PostCardReload => ({
    only: ['boardColumns', ...columnPaths()],
    reset: columnPaths(),
});

/** A card's actions (approve, reject, labels) reload every column from its first page. */
provide(postCardReloadKey, columnsReload);

/** The first page of each column the reloaded list brought that was not on the board (a new tag). */
const loadNewColumns = (): void => {
    const loaded = (page.props.columns ?? {}) as Record<string, unknown>;
    const paths = props.boardColumns
        .filter((column) => blank(loaded[column.key]))
        .map((column) => `columns.${column.key}`);

    if (filled(paths)) {
        router.reload({ only: paths, reset: paths });
    }
};

onUnmounted(
    router.on('finish', (event) => {
        const { visit } = event.detail;

        if (visit.completed && visit.only.includes('boardColumns')) {
            loadNewColumns();
        }
    }),
);

/** A burst of post events (a batch publishing) reloads the columns once. */
const refresh = useDebounceFn((): void => {
    router.reload(columnsReload());
}, 300);

useWorkspaceEcho(
    ['.post.created', '.post.deleted', '.post.platform.status.updated'],
    refresh,
);

const { open: openConnectDialog } = useConnectChannelDialog();
const inviteMemberDialogOpen = ref(false);

const openInviteMemberDialog = (): void => {
    inviteMemberDialogOpen.value = true;
};

const connectChannel = (): void => {
    openConnectDialog();
};

/** The tab's title: the channel's name, else the view's, resolved with the template's `$t`. */
const pageTitle = (translate: (key: string) => string): string => {
    if (filled(props.channel)) {
        return channelName(props.channel);
    }

    return translate('posts.board.view');
};

const showsChannelFilter = computed(
    () => props.scope === 'all' && filled(props.filterAccounts),
);

/** The Instagram grid's link, for a channel that has one. */
const gridHref = computed((): string | undefined => {
    if (!props.channel?.has_grid) {
        return undefined;
    }

    return grid.url(props.channel.id);
});

/** A new post starts on this page's channel, or on none from All channels. */
const newPostAccountIds = computed((): string[] => {
    if (filled(props.channel)) {
        return [props.channel.id];
    }

    return [];
});
</script>

<template>
    <Head :title="pageTitle($t)" />

    <AppLayout full-width>
        <template #header>
            <PublishHeader :channel="channel" />
        </template>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden"
            data-testid="publish-board"
        >
            <div
                class="mx-4 mt-2 flex h-12 shrink-0 items-center justify-between gap-2 border-b border-border-strong md:mx-8 md:gap-4"
                data-testid="board-toolbar"
            >
                <BoardGroupMenu v-model="selectedGroup" :groups="groups" />

                <div
                    class="flex min-w-0 items-center gap-2"
                    data-testid="board-filters"
                >
                    <PostChannelFilter
                        v-if="showsChannelFilter"
                        v-model="selectedChannelIds"
                        :channels="filterAccounts"
                        sheet-below-sm
                    />
                    <PublishFilterMenu
                        :timezone="timezone"
                        test-id="board"
                        :timezones="timezones"
                        @update:timezone="setTimezone"
                    >
                        <template #desktop-filters>
                            <LabelFilter
                                v-model="selectedLabelIds"
                                v-model:untagged="selectedUntagged"
                                :labels="labels"
                            />
                        </template>
                    </PublishFilterMenu>
                    <AppHeaderActions :on-phones="!channel">
                        <div class="flex items-center gap-2">
                            <ScheduleViewSwitch
                                active-view="board"
                                :list-href="listHref"
                                :calendar-href="calendarHref"
                                :board-href="boardUrl()"
                                :grid-href="gridHref"
                            />
                            <NewPostButton
                                :social-account-ids="newPostAccountIds"
                            />
                        </div>
                    </AppHeaderActions>
                </div>
            </div>

            <EmptyState
                v-if="!hasChannels"
                :title="$t('posts.publish.welcome.title')"
                :description="$t('posts.publish.welcome.description')"
                data-testid="publish-welcome"
            >
                <template #illustration>
                    <PublishEmptyIllustration />
                </template>
                <template v-if="canManageAccounts" #action>
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <Button
                            data-testid="publish-welcome-connect"
                            @click="connectChannel"
                        >
                            <IconPlus class="size-4" />
                            {{ $t('channels.connect') }}
                        </Button>
                        <Button
                            v-if="canManageTeam"
                            variant="outline"
                            data-testid="publish-welcome-invite"
                            @click="openInviteMemberDialog"
                        >
                            <IconUsers class="size-4" />
                            {{ $t('posts.publish.welcome.invite') }}
                        </Button>
                    </div>
                </template>
            </EmptyState>

            <EmptyState
                v-else-if="blank(boardColumns)"
                :title="$t('posts.board.no_columns.title')"
                :description="$t('posts.board.no_columns.description')"
                data-testid="board-no-columns"
            />

            <TooltipProvider v-else :delay-duration="0">
                <div
                    class="flex min-h-0 flex-1 snap-x snap-mandatory scroll-px-4 gap-3 overflow-x-auto overscroll-x-contain px-4 pt-4 pb-4 sm:snap-none md:px-8"
                    data-testid="board-columns"
                >
                    <BoardColumn
                        v-for="column in boardColumns"
                        :key="column.key"
                        :column="column"
                        :group="group"
                        :timezone="timezone"
                    />
                </div>
            </TooltipProvider>
        </div>

        <InviteMemberDialog
            v-if="canManageTeam"
            v-model:open="inviteMemberDialogOpen"
        />
    </AppLayout>

    <ConfirmDeleteModal
        ref="deleteModal"
        :title="$t('posts.edit.delete_modal.title')"
        :description="$t('posts.edit.delete_modal.description')"
        :action="$t('posts.edit.delete_modal.action')"
        :cancel="$t('posts.edit.delete_modal.cancel')"
    />
</template>
