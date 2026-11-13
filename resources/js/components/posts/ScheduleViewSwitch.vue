<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconCalendar,
    IconLayoutGrid,
    IconLayoutKanban,
    IconList,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { usePublishView } from '@/composables/usePublishView';
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

/** Picking a view here is the only thing that changes the remembered Publish view; the Instagram grid is not one. */
const pickView = (key: string): void => {
    if (PUBLISH_VIEWS.includes(key as PublishView)) {
        rememberPublishView(key as PublishView);
    }
};

const views = computed(() => [
    ...(props.gridHref
        ? [
              {
                  key: 'grid',
                  label: 'channels.grid.title',
                  icon: IconLayoutGrid,
                  href: props.gridHref,
                  note:
                      props.activeView === 'grid'
                          ? 'channels.grid.info'
                          : null,
              },
          ]
        : []),
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
]);
</script>

<template>
    <TooltipProvider :delay-duration="0">
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
                        :aria-current="
                            activeView === view.key ? 'page' : undefined
                        "
                        :data-testid="`schedule-view-${view.key}`"
                        @click="pickView(view.key)"
                        class="inline-flex h-6 items-center justify-center gap-1 rounded-md border border-transparent px-2 text-sm font-medium transition-control"
                        :class="
                            activeView === view.key
                                ? 'bg-primary-selected text-primary-text'
                                : 'text-foreground hover:bg-accent'
                        "
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
