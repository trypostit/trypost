<script setup lang="ts">
import {
    IconArrowBarToUp,
    IconArrowDown,
    IconArrowsMaximize,
    IconArrowUp,
    IconCopyPlus,
    IconDotsVertical,
    IconFileArrowLeft,
    IconRepeat,
    IconSend,
    IconTrash,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { isRecurring } from '@/lib/recurrence';
import { PostStatus, ScheduleMode } from '@/types/post';
import type { PostCard, PostCardMenuAction } from '@/types/publish';

const props = withDefaults(
    defineProps<{
        post: PostCard;
        testKey: string;
        canMoveUp?: boolean;
        canMoveDown?: boolean;
        movable?: boolean;
        details?: boolean;
    }>(),
    { canMoveUp: false, canMoveDown: false, movable: true, details: true },
);

const emit = defineEmits<{ select: [action: PostCardMenuAction] }>();

const { canPublishDirectly } = useWorkspaceAbilities();

const isScheduled = computed(
    () => props.post.status === PostStatus.Scheduled,
);
const isDraft = computed(() => props.post.status === PostStatus.Draft);
const canRecur = computed(
    () => isScheduled.value && props.post.scheduled_at !== null,
);
const isQueued = computed(
    () =>
        canPublishDirectly.value &&
        isScheduled.value &&
        props.post.schedule_mode === ScheduleMode.Queue &&
        props.movable !== false,
);
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <span class="inline-flex">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            class="data-[state=open]:bg-accent"
                            :aria-label="$t('posts.publish.actions.more')"
                            :data-testid="`post-card-menu-${testKey}`"
                        >
                            <IconDotsVertical class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    :data-testid="`post-card-menu-content-${testKey}`"
                >
                    <DropdownMenuItem
                        v-if="isDraft && canPublishDirectly"
                        :data-testid="`post-publish-now-${testKey}`"
                        @click="emit('select', 'publish_now')"
                    >
                        <IconSend class="size-4" />
                        {{ $t('posts.publish.actions.publish_now') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="isScheduled"
                        :data-testid="`post-move-drafts-${testKey}`"
                        @click="emit('select', 'move_drafts')"
                    >
                        <IconFileArrowLeft class="size-4" />
                        {{ $t('posts.publish.actions.move_to_drafts') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :data-testid="`post-duplicate-${testKey}`"
                        @click="emit('select', 'duplicate')"
                    >
                        <IconCopyPlus class="size-4" />
                        {{ $t('posts.publish.actions.duplicate') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canRecur && canPublishDirectly"
                        :data-testid="`post-recurrence-open-${testKey}`"
                        @click="emit('select', 'recurrence')"
                    >
                        <IconRepeat class="size-4" />
                        {{
                            isRecurring(post)
                                ? $t('posts.recurrence.edit')
                                : $t('posts.recurrence.make')
                        }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="details"
                        :data-testid="`post-details-open-${testKey}`"
                        @click="emit('select', 'details')"
                    >
                        <IconArrowsMaximize class="size-4" />
                        {{ $t('posts.show.title') }}
                    </DropdownMenuItem>
                    <template v-if="isQueued">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            :data-testid="`post-move-top-${testKey}`"
                            @click="emit('select', 'move_top')"
                        >
                            <IconArrowBarToUp class="size-4" />
                            {{ $t('posts.publish.actions.move_to_top') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="!canMoveUp"
                            :data-testid="`post-move-up-${testKey}`"
                            @click="emit('select', 'move_up')"
                        >
                            <IconArrowUp class="size-4" />
                            {{ $t('posts.publish.actions.move_up') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="!canMoveDown"
                            :data-testid="`post-move-down-${testKey}`"
                            @click="emit('select', 'move_down')"
                        >
                            <IconArrowDown class="size-4" />
                            {{ $t('posts.publish.actions.move_down') }}
                        </DropdownMenuItem>
                    </template>
                    <template v-if="post.can_delete">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            :data-testid="`post-delete-${testKey}`"
                            @click="emit('select', 'delete')"
                        >
                            <IconTrash class="size-4" />
                            {{ $t('posts.publish.actions.delete') }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
                </DropdownMenu>
            </span>
        </TooltipTrigger>
        <TooltipContent :data-testid="`post-card-menu-tooltip-${testKey}`">
            {{ $t('posts.publish.actions.more') }}
        </TooltipContent>
    </Tooltip>
</template>
