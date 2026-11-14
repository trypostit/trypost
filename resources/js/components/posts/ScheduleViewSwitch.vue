<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconCalendar,
    IconCheck,
    IconChevronDown,
    IconLayoutGrid,
    IconLayoutKanban,
    IconList,
} from '@tabler/icons-vue';
import { type Component, computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import { usePublishView } from '@/composables/usePublishView';
import { filled } from '@/lib/helpers';
import { calendar } from '@/routes/app';
import { board, index as postsIndex } from '@/routes/app/posts';
import { PUBLISH_VIEWS, type PublishView } from '@/types/publish';

const props = defineProps<{
    activeView: 'grid' | 'list' | 'calendar' | 'board';
    listHref?: string;
    calendarHref?: string;
    gridHref?: string;
    boardHref?: string;
}>();

const { rememberPublishView } = usePublishView();

/** On a phone the views are one menu: four icons and the New post button do not fit next to the page's title. */
const belowSm = useBelowBreakpoint('sm');

/** Picking a view here is the only thing that changes the remembered Publish view; the Instagram grid is not one. */
const pickView = (key: string): void => {
    if (PUBLISH_VIEWS.includes(key as PublishView)) {
        rememberPublishView(key as PublishView);
    }
};

interface ScheduleView {
    key: string;
    label: string;
    icon: Component;
    href: string;
    note: string | null;
}

/** The Instagram grid explains itself while it is the open view. */
const gridNote = (): string | null => {
    if (props.activeView !== 'grid') {
        return null;
    }

    return 'channels.grid.info';
};

/** The views of the page; the Instagram grid comes first on a channel that has one. */
const views = computed((): ScheduleView[] => {
    const publishViews: ScheduleView[] = [
        {
            key: 'list',
            label: 'posts.list_view',
            icon: IconList,
            href: props.listHref ?? postsIndex.url(),
            note: null,
        },
        {
            key: 'calendar',
            label: 'calendar.title',
            icon: IconCalendar,
            href: props.calendarHref ?? calendar.url({ view: 'week' }),
            note: null,
        },
        {
            key: 'board',
            label: 'posts.board.view',
            icon: IconLayoutKanban,
            href: props.boardHref ?? board.url(),
            note: null,
        },
    ];

    if (!filled(props.gridHref)) {
        return publishViews;
    }

    return [
        {
            key: 'grid',
            label: 'channels.grid.title',
            icon: IconLayoutGrid,
            href: props.gridHref,
            note: gridNote(),
        },
        ...publishViews,
    ];
});

/** The view the page shows, which the phone menu's button carries. */
const activeViewItem = computed(() => {
    const active = views.value.find((view) => view.key === props.activeView);

    if (filled(active)) {
        return active;
    }

    return views.value[0];
});

/** The open view's link is the current page for screen readers. */
const ariaCurrent = (key: string): 'page' | undefined => {
    if (props.activeView !== key) {
        return undefined;
    }

    return 'page';
};

const isActive = (key: string): boolean => props.activeView === key;

const viewLinkClass = (key: string): string => {
    if (isActive(key)) {
        return 'bg-primary-selected text-primary-text';
    }

    return 'text-foreground hover:bg-accent';
};
</script>

<template>
    <DropdownMenu v-if="belowSm">
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                class="h-8 gap-1 px-2 data-[state=open]:bg-accent"
                :aria-label="$t('posts.view_switcher')"
                data-testid="schedule-view-trigger"
            >
                <component :is="activeViewItem.icon" class="size-4" />
                <IconChevronDown class="size-4 text-muted-foreground" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" data-testid="schedule-view-menu">
            <DropdownMenuItem v-for="view in views" :key="view.key" as-child>
                <Link
                    :href="view.href"
                    :aria-current="ariaCurrent(view.key)"
                    :data-testid="`schedule-view-${view.key}`"
                    @click="pickView(view.key)"
                >
                    <component :is="view.icon" class="size-4" />
                    {{ $t(view.label) }}
                    <IconCheck v-if="isActive(view.key)" class="ms-auto size-4" />
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
    <TooltipProvider v-else :delay-duration="0">
        <nav
            class="inline-flex h-8 items-center gap-1 rounded-lg border border-border-strong bg-card p-[3px]"
            :aria-label="$t('posts.view_switcher')"
        >
            <Tooltip
                v-for="view in views"
                :key="view.key"
                :disabled="!view.note"
            >
                <TooltipTrigger as-child>
                    <Link
                        :href="view.href"
                        :aria-current="ariaCurrent(view.key)"
                        :data-testid="`schedule-view-${view.key}`"
                        @click="pickView(view.key)"
                        class="inline-flex h-6 items-center justify-center gap-1 rounded-md border border-transparent px-2 text-sm font-medium transition-control"
                        :class="viewLinkClass(view.key)"
                    >
                        <component :is="view.icon" class="size-4" />
                        <span class="hidden sm:inline">{{
                            $t(view.label)
                        }}</span>
                    </Link>
                </TooltipTrigger>
                <TooltipContent
                    v-if="view.note"
                    :data-testid="`schedule-view-${view.key}-note`"
                >
                    {{ $t(view.note) }}
                </TooltipContent>
            </Tooltip>
        </nav>
    </TooltipProvider>
</template>
