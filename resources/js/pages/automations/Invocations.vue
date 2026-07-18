<script setup lang="ts">
import { InfiniteScroll, router, useHttp } from '@inertiajs/vue3';
import { IconChevronRight, IconCopy, IconRefresh } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { showRun as showRunRoute } from '@/actions/App/Http/Controllers/App/AutomationController';
import AutomationDetailLayout from '@/components/automations/AutomationDetailLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import TableLoadMore from '@/components/ui/table/TableLoadMore.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import date from '@/date';
import type { Automation } from '@/types/automation/automation';
import type { NodeRunStatusValue } from '@/types/automation/node-run-status';
import type { NodeTypeValue } from '@/types/automation/node-type';
import { RunStatus, type RunStatusValue } from '@/types/automation/run-status';

type Invocation = {
    id: string;
    status: RunStatusValue;
    is_manual: boolean;
    node_run_count: number;
    duration_ms: number | null;
    error_message: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
};

type NodeRun = {
    id: string;
    node_id: string;
    node_type: NodeTypeValue;
    status: NodeRunStatusValue;
    error: { message?: string } | null;
    started_at: string | null;
    finished_at: string | null;
};

const props = defineProps<{
    automation: Automation;
    invocations: { data: Invocation[] };
    filters: { status: string | null; search: string | null };
}>();

const statusFilter = ref(props.filters.status ?? 'all');
const search = ref(props.filters.search ?? '');

const statusLabel = computed(() =>
    statusFilter.value === 'all'
        ? trans('automations.invocations.filter.all')
        : trans(`automations.status_run.${statusFilter.value}`),
);

const statusVariant = (
    status: RunStatusValue | NodeRunStatusValue,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === RunStatus.Completed) return 'default';
    if (status === RunStatus.Failed || status === RunStatus.Cancelled) return 'destructive';
    if (status === RunStatus.Running || status === RunStatus.Waiting) return 'secondary';
    return 'outline';
};

const summary = (invocation: Invocation): string => {
    if (invocation.status === RunStatus.Failed)
        return (
            invocation.error_message ??
            trans('automations.invocations.summary.failed')
        );
    if (invocation.status === RunStatus.Completed)
        return trans('automations.invocations.summary.completed');
    if (invocation.status === RunStatus.Running || invocation.status === RunStatus.Waiting)
        return trans('automations.invocations.summary.running');
    if (invocation.status === RunStatus.Cancelled)
        return trans('automations.invocations.summary.cancelled');
    return trans('automations.invocations.summary.pending');
};


const stepsLabel = (count: number): string =>
    transChoice('automations.invocations.steps', count, {
        count: String(count),
    });

const copyId = (id: string) => {
    navigator.clipboard.writeText(id);
    toast.success(trans('automations.invocations.copied'));
};

let searchTimer: ReturnType<typeof setTimeout> | undefined;

const isRefreshing = ref(false);

const reload = () => {
    router.reload({
        data: {
            status:
                statusFilter.value === 'all' ? undefined : statusFilter.value,
            search: search.value || undefined,
        },
        only: ['invocations', 'filters'],
        // `invocations` is an Inertia scroll/merge prop, so a plain reload would
        // APPEND the filtered page onto the existing rows instead of replacing
        // them. Resetting the prop clears the merged list before the new results.
        reset: ['invocations'],
        onStart: () => {
            isRefreshing.value = true;
        },
        onFinish: () => {
            isRefreshing.value = false;
        },
    });
};

watch(statusFilter, reload);

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 350);
});

const expanded = ref<Record<string, boolean>>({});
const nodeRuns = ref<Record<string, NodeRun[]>>({});
const loadingRuns = ref<Record<string, boolean>>({});

const runHttp = useHttp<Record<string, never>, { node_runs: NodeRun[] }>({});

const toggleExpand = async (invocation: Invocation) => {
    expanded.value[invocation.id] = !expanded.value[invocation.id];

    if (expanded.value[invocation.id] && !nodeRuns.value[invocation.id]) {
        loadingRuns.value[invocation.id] = true;
        try {
            const payload = await runHttp.get(showRunRoute.url({ automation: props.automation.id, run: invocation.id }));
            nodeRuns.value[invocation.id] = payload.node_runs ?? [];
        } catch {
            toast.error(trans('automations.invocations.load_error'));
        } finally {
            loadingRuns.value[invocation.id] = false;
        }
    }
};
</script>

<template>
    <AutomationDetailLayout :automation="automation" current="invocations">
        <div class="space-y-4 p-4">
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                <div class="flex items-center gap-2 sm:contents">
                    <Select v-model="statusFilter">
                        <SelectTrigger
                            dusk="invocations-status-filter"
                            class="w-full sm:w-44"
                        >
                            <SelectValue>{{ statusLabel }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">{{
                                $t('automations.invocations.filter.all')
                            }}</SelectItem>
                            <SelectItem value="completed">{{
                                $t('automations.status_run.completed')
                            }}</SelectItem>
                            <SelectItem value="failed">{{
                                $t('automations.status_run.failed')
                            }}</SelectItem>
                            <SelectItem value="running">{{
                                $t('automations.status_run.running')
                            }}</SelectItem>
                            <SelectItem value="waiting">{{
                                $t('automations.status_run.waiting')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="shrink-0 sm:order-last"
                                    :aria-label="
                                        $t('automations.invocations.refresh')
                                    "
                                    :disabled="isRefreshing"
                                    dusk="invocations-refresh"
                                    @click="reload"
                                >
                                    <IconRefresh
                                        class="size-4"
                                        :class="{ 'animate-spin': isRefreshing }"
                                    />
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>{{
                                $t('automations.invocations.refresh')
                            }}</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
                <Input
                    v-model="search"
                    :placeholder="
                        $t('automations.invocations.search_placeholder')
                    "
                    class="sm:max-w-xs"
                    dusk="invocations-search"
                />
            </div>

            <div class="relative">
                <div
                    :class="{
                        'pointer-events-none opacity-50 transition-opacity':
                            isRefreshing,
                    }"
                >
                    <div
                        v-if="invocations.data.length === 0"
                        class="rounded-xl border-2 border-dashed border-foreground/25 bg-card p-12 text-center"
                    >
                        <p class="text-foreground/60">
                            {{ $t('automations.invocations.empty') }}
                        </p>
                    </div>

                    <InfiniteScroll
                        v-else
                        data="invocations"
                        items-element="#invocations-body"
                        preserve-url
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-8"></TableHead>
                                    <TableHead>{{
                                        $t(
                                            'automations.invocations.columns.timestamp',
                                        )
                                    }}</TableHead>
                                    <TableHead>{{
                                        $t(
                                            'automations.invocations.columns.run',
                                        )
                                    }}</TableHead>
                                    <TableHead>{{
                                        $t(
                                            'automations.invocations.columns.status',
                                        )
                                    }}</TableHead>
                                    <TableHead>{{
                                        $t(
                                            'automations.invocations.columns.message',
                                        )
                                    }}</TableHead>
                                    <TableHead class="text-right">{{
                                        $t(
                                            'automations.invocations.columns.duration',
                                        )
                                    }}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody id="invocations-body">
                                <template
                                    v-for="invocation in invocations.data"
                                    :key="invocation.id"
                                >
                                    <TableRow
                                        class="cursor-pointer"
                                        :dusk="`invocation-row-${invocation.id}`"
                                        @click="toggleExpand(invocation)"
                                    >
                                        <TableCell>
                                            <IconChevronRight
                                                class="size-4 text-foreground/40 transition-transform"
                                                :class="{
                                                    'rotate-90':
                                                        expanded[invocation.id],
                                                }"
                                            />
                                        </TableCell>
                                        <TableCell
                                            class="text-sm whitespace-nowrap text-foreground/70"
                                            >{{
                                                date.diffForHumans(
                                                    invocation.created_at,
                                                )
                                            }}</TableCell
                                        >
                                        <TableCell>
                                            <button
                                                type="button"
                                                class="flex items-center gap-1.5 font-mono text-xs text-foreground/60 hover:text-foreground"
                                                @click.stop="
                                                    copyId(invocation.id)
                                                "
                                            >
                                                {{ invocation.id.slice(0, 8) }}
                                                <IconCopy class="size-3" />
                                            </button>
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                :variant="
                                                    statusVariant(
                                                        invocation.status,
                                                    )
                                                "
                                                >{{
                                                    $t(
                                                        `automations.status_run.${invocation.status}`,
                                                    )
                                                }}</Badge
                                            >
                                        </TableCell>
                                        <TableCell class="max-w-md">
                                            <p
                                                class="truncate text-sm font-medium"
                                            >
                                                {{ summary(invocation) }}
                                            </p>
                                            <p
                                                class="text-xs text-foreground/50"
                                            >
                                                {{
                                                    stepsLabel(
                                                        invocation.node_run_count,
                                                    )
                                                }}
                                            </p>
                                        </TableCell>
                                        <TableCell
                                            class="text-right text-sm text-foreground/70 tabular-nums"
                                            >{{
                                                date.formatDurationMs(
                                                    invocation.duration_ms,
                                                )
                                            }}</TableCell
                                        >
                                    </TableRow>
                                    <TableRow
                                        v-if="expanded[invocation.id]"
                                        :key="`${invocation.id}-detail`"
                                    >
                                        <TableCell
                                            colspan="6"
                                            class="bg-muted/30"
                                        >
                                            <div
                                                v-if="
                                                    loadingRuns[invocation.id]
                                                "
                                                class="py-3 text-center text-sm text-foreground/50"
                                            >
                                                {{
                                                    $t(
                                                        'automations.invocations.loading',
                                                    )
                                                }}
                                            </div>
                                            <ul
                                                v-else-if="
                                                    (
                                                        nodeRuns[
                                                            invocation.id
                                                        ] ?? []
                                                    ).length > 0
                                                "
                                                class="divide-y divide-foreground/5"
                                            >
                                                <li
                                                    v-for="nodeRun in nodeRuns[
                                                        invocation.id
                                                    ]"
                                                    :key="nodeRun.id"
                                                    class="flex items-center justify-between gap-4 py-2"
                                                >
                                                    <div
                                                        class="flex items-center gap-2"
                                                    >
                                                        <Badge
                                                            :variant="
                                                                statusVariant(
                                                                    nodeRun.status,
                                                                )
                                                            "
                                                            class="font-mono text-[10px]"
                                                            >{{
                                                                nodeRun.status
                                                            }}</Badge
                                                        >
                                                        <span
                                                            class="text-sm font-medium"
                                                            >{{
                                                                $t(
                                                                    `automations.node_type.${nodeRun.node_type}`,
                                                                )
                                                            }}</span
                                                        >
                                                    </div>
                                                    <span
                                                        v-if="
                                                            nodeRun.error
                                                                ?.message
                                                        "
                                                        class="truncate text-xs text-destructive"
                                                        >{{
                                                            nodeRun.error
                                                                .message
                                                        }}</span
                                                    >
                                                </li>
                                            </ul>
                                            <p
                                                v-else
                                                class="py-3 text-center text-sm text-foreground/50"
                                            >
                                                {{
                                                    $t(
                                                        'automations.invocations.no_steps',
                                                    )
                                                }}
                                            </p>
                                        </TableCell>
                                    </TableRow>
                                </template>
                            </TableBody>
                        </Table>

                        <template #next="{ loading }">
                            <TableLoadMore v-if="loading" />
                        </template>
                    </InfiniteScroll>
                </div>

                <div
                    v-if="isRefreshing"
                    class="absolute inset-x-0 top-16 flex justify-center"
                >
                    <Spinner class="size-6" />
                </div>
            </div>
        </div>
    </AutomationDetailLayout>
</template>
