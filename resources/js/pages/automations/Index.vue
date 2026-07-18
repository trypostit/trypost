<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import { IconBolt, IconCircleCheck, IconCircleDot, IconCircleX, IconPlus } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableLoadMore,
    TableRow,
} from '@/components/ui/table';
import dayjs from '@/dayjs';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    metrics as metricsAutomation,
    store as storeAutomation,
    workflow as workflowAutomation,
} from '@/routes/app/automations';
import type { Automation } from '@/types/automation/automation';

interface Props {
    automations: {
        data: Automation[];
        meta: {
            hasNextPage: boolean;
        };
    };
}

defineProps<Props>();

const statusConfig = (status: string) => {
    const configs: Record<string, { icon: typeof IconCircleDot; label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
        draft: { icon: IconCircleDot, label: trans('automations.status.draft'), variant: 'outline' },
        active: { icon: IconCircleCheck, label: trans('automations.status.active'), variant: 'default' },
        paused: { icon: IconCircleX, label: trans('automations.status.paused'), variant: 'secondary' },
    };
    return configs[status] ?? configs['draft'];
};

const formatDate = (date: string) => dayjs.utc(date).local().format('D MMM YYYY');

const isCreating = ref(false);

const handleCreate = () => {
    if (isCreating.value) return;
    isCreating.value = true;
    router.post(storeAutomation.url(), { name: trans('automations.default_name') }, {
        onFinish: () => { isCreating.value = false; },
    });
};

// A draft opens on the builder to be set up; a live automation opens on its
// metrics, where the user watches it run.
const openAutomation = (automation: Automation) => {
    const route = automation.status === 'draft' ? workflowAutomation : metricsAutomation;
    router.visit(route.url(automation.id));
};
</script>

<template>
    <Head :title="$t('automations.title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <PageHeader :title="$t('automations.title')" />

                <Button @click="handleCreate" :disabled="isCreating">
                    <IconPlus class="size-4" />
                    {{ $t('automations.actions.new') }}
                </Button>
            </div>

            <EmptyState
                v-if="automations.data.length === 0"
                :icon="IconBolt"
                :title="$t('automations.index.empty_title')"
                :description="$t('automations.index.empty_description')"
            >
                <template #action>
                    <Button @click="handleCreate" :disabled="isCreating">
                        <IconPlus class="size-4" />
                        {{ $t('automations.actions.new') }}
                    </Button>
                </template>
            </EmptyState>

            <div v-else>
                <InfiniteScroll data="automations" items-element="#automations-body" preserve-url>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ $t('automations.index.columns.name') }}</TableHead>
                                <TableHead>{{ $t('automations.index.columns.status') }}</TableHead>
                                <TableHead>{{ $t('automations.index.columns.created') }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody id="automations-body">
                            <TableRow
                                v-for="automation in automations.data"
                                :key="automation.id"
                                class="cursor-pointer"
                                @click="openAutomation(automation)"
                            >
                                <TableCell class="font-medium">{{ automation.name }}</TableCell>
                                <TableCell>
                                    <Badge :variant="statusConfig(automation.status).variant">
                                        <component :is="statusConfig(automation.status).icon" class="size-3" />
                                        {{ statusConfig(automation.status).label }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ automation.created_at ? formatDate(automation.created_at) : '' }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <template #next="{ loading }">
                        <TableLoadMore v-if="loading" />
                    </template>
                </InfiniteScroll>
            </div>
        </div>
    </AppLayout>
</template>
