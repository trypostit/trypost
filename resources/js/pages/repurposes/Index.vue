<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconRepeat, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import CreateRepurposeDialog from '@/components/repurpose/CreateRepurposeDialog.vue';
import RepurposeTemplateCard from '@/components/repurpose/RepurposeTemplateCard.vue';
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
import { destroy, show } from '@/routes/app/repurposes';
import type { ChannelAccount } from '@/types/channel';
import type { Repurpose, RepurposeTemplate } from '@/types/repurpose';
import { repurposeStatusVariant } from '@/types/repurpose-status';

defineProps<{
    repurposes: Repurpose[];
    templates: RepurposeTemplate[];
    sourceAccounts: ChannelAccount[];
}>();

const createDialogOpen = ref(false);
const activeTemplate = ref<RepurposeTemplate | null>(null);
const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const openRepurpose = (repurpose: Repurpose) => {
    router.visit(show.url(repurpose.id));
};

const startFromTemplate = (template: RepurposeTemplate) => {
    activeTemplate.value = template;
    createDialogOpen.value = true;
};

const startBlank = () => {
    activeTemplate.value = null;
    createDialogOpen.value = true;
};

const handleDelete = (repurpose: Repurpose) => {
    confirmDeleteModal.value?.open({
        url: destroy.url(repurpose.id),
        confirmText: trans('common.confirm_modal.delete_keyword'),
    });
};
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

            <div v-if="repurposes.length === 0" class="space-y-6">
                <EmptyState
                    :icon="IconRepeat"
                    :title="$t('repurposes.empty.title')"
                    :description="$t('repurposes.empty.description')"
                />

                <div class="grid gap-4 sm:grid-cols-2">
                    <RepurposeTemplateCard
                        v-for="template in templates"
                        :key="template.key"
                        :template="template"
                        @use="startFromTemplate"
                    />
                </div>
            </div>

            <Table v-else data-testid="repurposes-table">
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ $t('repurposes.table.source') }}</TableHead>
                        <TableHead>{{ $t('repurposes.table.destinations') }}</TableHead>
                        <TableHead>{{ $t('repurposes.table.status') }}</TableHead>
                        <TableHead>{{ $t('repurposes.table.published') }}</TableHead>
                        <TableHead>{{ $t('repurposes.table.last_polled') }}</TableHead>
                        <TableHead />
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <TableRow
                        v-for="repurpose in repurposes"
                        :key="repurpose.id"
                        class="cursor-pointer"
                        :data-testid="`repurpose-row-${repurpose.id}`"
                        @click="openRepurpose(repurpose)"
                    >
                        <TableCell>{{ repurpose.source_account?.display_label }}</TableCell>
                        <TableCell>{{ repurpose.destinations.length }}</TableCell>
                        <TableCell>
                            <Badge :variant="repurposeStatusVariant(repurpose.status)">
                                {{ $t(`repurposes.status.${repurpose.status}`) }}
                            </Badge>
                        </TableCell>
                        <TableCell>{{ repurpose.published_items_count ?? 0 }}</TableCell>
                        <TableCell>
                            {{ repurpose.last_polled_at ? date.diffForHumans(repurpose.last_polled_at) : '—' }}
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="$t('common.delete')"
                                @click.stop="handleDelete(repurpose)"
                            >
                                <IconTrash class="size-4" />
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <CreateRepurposeDialog
            v-model:open="createDialogOpen"
            :source-accounts="sourceAccounts"
            :template="activeTemplate?.key ?? null"
            :locked-platform="activeTemplate?.source_platform ?? null"
        />

        <ConfirmDeleteModal ref="confirmDeleteModal" />
    </AppLayout>
</template>
