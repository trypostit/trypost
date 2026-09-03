<script setup lang="ts">
import {
    IconCircleX,
    IconClock,
    IconGitBranch,
    IconGripVertical,
    IconRss,
    IconSend,
    IconSparkles,
    IconWorld,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { NodeType } from '@/types/automation/node-type';

const categories = computed(() => [
    {
        title: trans('automations.categories.sources'),
        nodes: [
            { type: NodeType.FetchRss, label: trans('automations.nodes.fetch_rss'), icon: IconRss, accent: 'amber' },
            { type: NodeType.HttpRequest, label: trans('automations.nodes.http_request'), icon: IconWorld, accent: 'slate' },
        ],
    },
    {
        title: trans('automations.categories.content'),
        nodes: [
            { type: NodeType.Generate, label: trans('automations.nodes.generate'), icon: IconSparkles, accent: 'blue' },
        ],
    },
    {
        title: trans('automations.categories.flow'),
        nodes: [
            { type: NodeType.Condition, label: trans('automations.nodes.condition'), icon: IconGitBranch, accent: 'rose' },
            { type: NodeType.Delay, label: trans('automations.nodes.delay'), icon: IconClock, accent: 'cyan' },
            { type: NodeType.End, label: trans('automations.nodes.end'), icon: IconCircleX, accent: 'zinc' },
        ],
    },
    {
        title: trans('automations.categories.output'),
        nodes: [
            { type: NodeType.Publish, label: trans('automations.nodes.publish'), icon: IconSend, accent: 'emerald' },
        ],
    },
]);

const accentClasses: Record<string, { tint: string; text: string }> = {
    violet: { tint: 'bg-violet-200', text: 'text-violet-900' },
    blue: { tint: 'bg-blue-200', text: 'text-blue-900' },
    amber: { tint: 'bg-amber-200', text: 'text-amber-900' },
    rose: { tint: 'bg-rose-200', text: 'text-rose-900' },
    emerald: { tint: 'bg-emerald-200', text: 'text-emerald-900' },
    slate: { tint: 'bg-slate-200', text: 'text-slate-900' },
    zinc: { tint: 'bg-zinc-200', text: 'text-zinc-900' },
    cyan: { tint: 'bg-cyan-200', text: 'text-cyan-900' },
};

const onDragStart = (event: DragEvent, nodeType: string) => {
    if (!event.dataTransfer) return;
    event.dataTransfer.setData('application/automation-node-type', nodeType);
    event.dataTransfer.effectAllowed = 'move';
};
</script>

<template>
    <div class="flex flex-col gap-5">
        <div v-for="category in categories" :key="category.title" class="flex flex-col gap-2">
            <p class="px-0.5 text-[11px] font-black uppercase tracking-widest text-foreground/45">
                {{ category.title }}
            </p>
            <button
                v-for="option in category.nodes"
                :key="option.type"
                draggable="true"
                class="group flex cursor-grab items-center gap-2.5 rounded-xl border-2 border-foreground bg-card p-2.5 text-left text-sm font-bold text-foreground shadow-[2px_2px_0_var(--foreground)] transition-all hover:-translate-x-px hover:-translate-y-px hover:shadow-[3px_3px_0_var(--foreground)] active:translate-x-0 active:translate-y-0 active:rotate-[-1deg] active:cursor-grabbing active:shadow-[1px_1px_0_var(--foreground)]"
                @dragstart="onDragStart($event, option.type)"
            >
                <div :class="['flex size-7 -rotate-3 items-center justify-center rounded-md border-2 border-foreground', accentClasses[option.accent].tint]">
                    <component :is="option.icon" :size="14" :class="accentClasses[option.accent].text" />
                </div>
                <span class="flex-1">{{ option.label }}</span>
                <IconGripVertical :size="16" class="shrink-0 text-foreground/30 transition-colors group-hover:text-foreground/55" />
            </button>
        </div>
    </div>
</template>
