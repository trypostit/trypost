<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import { IconAlertTriangle, IconRepeat } from '@tabler/icons-vue';
import { ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import CreateRepurposeDialog from '@/components/repurpose/CreateRepurposeDialog.vue';
import RepurposeFlow from '@/components/repurpose/RepurposeFlow.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { show } from '@/routes/app/repurposes';
import type { ChannelAccount } from '@/types/channel';
import type { FlowNode, Repurpose } from '@/types/repurpose';
import { repurposeStatusVariant } from '@/types/repurpose-status';

const props = defineProps<{
    repurposes: { data: Repurpose[] };
    sourceAccounts: ChannelAccount[];
    destinationAccounts: ChannelAccount[];
}>();

const createDialogOpen = ref(false);

const openRepurpose = (repurpose: Repurpose) => {
    router.visit(show.url(repurpose.id));
};


const startBlank = () => {
    createDialogOpen.value = true;
};

const destinationNodes = (repurpose: Repurpose): FlowNode[] =>
    repurpose.destinations.flatMap((destination) => {
        const account = props.destinationAccounts.find((item) => item.id === destination.social_account_id);

        return account
            ? [{ platform: account.platform, label: account.display_name, username: account.username }]
            : [];
    });

</script>

<template>
    <Head :title="$t('repurposes.title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <PageHeader :title="$t('repurposes.title')" :description="$t('repurposes.description')" />

            <div class="flex justify-end">
                <Button data-testid="create-repurpose-button" @click="startBlank">
                    {{ $t('repurposes.new') }}
                </Button>
            </div>

            <EmptyState
                v-if="repurposes.data.length === 0"
                :icon="IconRepeat"
                :title="$t('repurposes.empty.title')"
                :description="$t('repurposes.empty.description')"
            />

            <InfiniteScroll v-else data="repurposes" items-element="#repurposes-body" preserve-url>
            <Table data-testid="repurposes-table">
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-full">{{ $t('repurposes.table.flow') }}</TableHead>
                        <TableHead class="whitespace-nowrap">{{ $t('repurposes.table.status') }}</TableHead>
                        <TableHead class="whitespace-nowrap text-center">{{ $t('repurposes.table.published') }}</TableHead>
                        <TableHead class="whitespace-nowrap text-right">{{ $t('repurposes.table.last_polled') }}</TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody id="repurposes-body">
                    <TableRow
                        v-for="repurpose in repurposes.data"
                        :key="repurpose.id"
                        class="cursor-pointer"
                        :data-testid="`repurpose-row-${repurpose.id}`"
                        @click="openRepurpose(repurpose)"
                    >
                        <TableCell>
                            <RepurposeFlow
                                :source="{
                                    platform: repurpose.source_account?.platform ?? '',
                                    label: repurpose.source_account?.display_name,
                                    username: repurpose.source_account?.username,
                                }"
                                :destinations="destinationNodes(repurpose)"
                                size="sm"
                                align="start"
                            />
                        </TableCell>
                        <TableCell class="whitespace-nowrap">
                            <div class="flex items-center gap-1.5">
                                <Badge :variant="repurposeStatusVariant(repurpose.status)">
                                    {{ $t(`repurposes.status.${repurpose.status}`) }}
                                </Badge>

                                <IconAlertTriangle
                                    v-if="repurpose.paused_reason"
                                    class="size-4 text-amber-500"
                                    :title="$t('repurposes.health.stopped_itself')"
                                    data-testid="repurpose-stopped-itself"
                                />
                            </div>
                        </TableCell>
                        <TableCell class="text-center tabular-nums">
                            {{ repurpose.published_items_count ?? 0 }}
                        </TableCell>
                        <TableCell class="whitespace-nowrap text-right text-foreground/70">
                            {{ repurpose.last_polled_at ? date.diffForHumans(repurpose.last_polled_at) : '—' }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
            </InfiniteScroll>
        </div>

        <CreateRepurposeDialog
            v-model:open="createDialogOpen"
            :source-accounts="sourceAccounts"
        />

    </AppLayout>
</template>
