<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconPencil } from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { settings } from '@/routes/app/channels';

const props = defineProps<{
    channelId: string;
    sent: number;
    scheduled: number;
    goal: number;
}>();

const percent = computed(() =>
    Math.min(100, Math.round((props.sent / Math.max(props.goal, 1)) * 100)),
);

const toDo = computed(() =>
    Math.max(0, props.goal - props.sent - props.scheduled),
);
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="flex min-w-0 items-center gap-1 rounded-md text-sm text-muted-foreground transition-control hover:text-foreground data-[state=open]:text-foreground"
                data-testid="publish-goal-progress"
            >
            <svg
                viewBox="0 0 14 14"
                class="size-3.5 shrink-0 -rotate-90"
                aria-hidden="true"
                data-testid="publish-goal-pie"
                :data-percent="percent"
            >
                <circle
                    cx="7"
                    cy="7"
                    r="6.25"
                    fill="none"
                    stroke-width="1.5"
                    class="stroke-success"
                />
                <circle
                    v-if="percent > 0"
                    cx="7"
                    cy="7"
                    r="2.25"
                    fill="none"
                    stroke-width="4.5"
                    pathLength="100"
                    :stroke-dasharray="`${percent} 100`"
                    class="stroke-success"
                />
            </svg>
                <span class="truncate">{{
                    $t('posts.publish.goal', {
                        sent: String(sent),
                        goal: String(goal),
                    })
                }}</span>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="w-auto min-w-64 p-0"
            data-testid="publish-goal-popover"
        >
            <div class="flex items-start justify-between gap-4 px-4 py-3">
                <div class="min-w-0">
                    <p class="text-xs text-muted-foreground">
                        {{ $t('posts.publish.goal_popover.title') }}
                    </p>
                    <p class="text-sm font-emphasis text-foreground">
                        {{
                            $tChoice(
                                'posts.publish.goal_popover.per_week',
                                goal,
                                { count: String(goal) },
                            )
                        }}
                    </p>
                </div>
                <Button
                    as-child
                    variant="ghost"
                    size="icon-sm"
                    class="shrink-0"
                >
                    <Link
                        :href="settings.url(channelId)"
                        :aria-label="$t('posts.publish.goal_popover.edit')"
                        data-testid="publish-goal-edit"
                    >
                        <IconPencil class="size-4" />
                    </Link>
                </Button>
            </div>
            <div
                class="flex items-center gap-2 border-t border-border px-4 py-3 text-sm whitespace-nowrap text-muted-foreground"
                data-testid="publish-goal-summary"
            >
                <span
                    ><span class="font-emphasis text-foreground">{{ sent }}</span>
                    {{ $t('posts.publish.goal_popover.sent') }}</span
                >
                <span aria-hidden="true">·</span>
                <span
                    ><span class="font-emphasis text-foreground">{{
                        scheduled
                    }}</span>
                    {{ $t('posts.publish.goal_popover.scheduled') }}</span
                >
                <span aria-hidden="true">·</span>
                <span
                    ><span class="font-emphasis text-foreground">{{ toDo }}</span>
                    {{ $t('posts.publish.goal_popover.to_do') }}</span
                >
            </div>
        </PopoverContent>
    </Popover>
</template>
