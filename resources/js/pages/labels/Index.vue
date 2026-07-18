<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import { IconPencil, IconSearch, IconTag, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import CreateDialog from '@/components/labels/CreateDialog.vue';
import EditDialog from '@/components/labels/EditDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import debounce from '@/debounce';
import AppLayout from '@/layouts/AppLayout.vue';
import { destroy as labelsDestroy, index as labelsIndex } from '@/routes/app/labels';

interface Label {
    id: string;
    name: string;
    color: string;
    created_at: string;
}

interface ScrollLabels {
    data: Label[];
    meta: { hasNextPage: boolean };
}

interface Props {
    labels: ScrollLabels;
    filters: { search: string };
}

const props = defineProps<Props>();

const searchQuery = ref(props.filters.search);

const search = debounce(() => {
    router.get(
        labelsIndex.url(),
        { search: searchQuery.value || undefined },
        { preserveState: true, preserveScroll: true, reset: ['labels'] },
    );
}, 300);

watch(searchQuery, () => search());

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
const isCreateDialogOpen = ref(false);
const isEditDialogOpen = ref(false);
const editingLabel = ref<Label | null>(null);

const openEditDialog = (label: Label) => {
    editingLabel.value = label;
    isEditDialogOpen.value = true;
};

const handleDelete = (label: Label) => {
    deleteModal.value?.open({
        url: labelsDestroy.url(label.id),
        confirmText: label.name,
    });
};

const formatDate = (date: string): string => dayjs.utc(date).local().format('D MMM YYYY');

const hasActiveSearch = computed(() => Boolean(searchQuery.value?.trim()));
</script>

<template>
    <Head :title="$t('labels.title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <PageHeader :title="$t('labels.title')" :description="$t('labels.description')" />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:w-auto">
                    <IconSearch class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        v-model="searchQuery"
                        :placeholder="trans('labels.search')"
                        class="w-full pl-9 sm:w-64"
                    />
                </div>

                <Button @click="isCreateDialogOpen = true">{{ $t('labels.new_label') }}</Button>
            </div>

            <EmptyState
                v-if="labels.data.length === 0"
                :icon="IconTag"
                :title="hasActiveSearch ? $t('labels.no_search_results') : $t('labels.no_labels_yet')"
                :description="hasActiveSearch ? $t('labels.try_different_search') : $t('labels.description')"
            />

            <div v-else>
                <InfiniteScroll data="labels" items-element="#labels-body" preserve-url>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-12" />
                                <TableHead>{{ $t('labels.table.name') }}</TableHead>
                                <TableHead>{{ $t('labels.table.created_at') }}</TableHead>
                                <TableHead class="text-right" />
                            </TableRow>
                        </TableHeader>
                        <TableBody id="labels-body">
                            <TableRow
                                v-for="label in labels.data"
                                :key="label.id"
                                class="cursor-pointer"
                                @click="openEditDialog(label)"
                            >
                                <TableCell>
                                    <div
                                        class="size-6 rounded-md border-2 border-foreground shadow-2xs"
                                        :style="{ backgroundColor: label.color }"
                                    />
                                </TableCell>
                                <TableCell>{{ label.name }}</TableCell>
                                <TableCell>{{ formatDate(label.created_at) }}</TableCell>
                                <TableCell class="text-right" @click.stop>
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            class="size-8"
                                            :aria-label="$t('labels.actions.edit')"
                                            @click="openEditDialog(label)"
                                        >
                                            <IconPencil class="size-4" />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            class="size-8 bg-rose-100 hover:bg-rose-200"
                                            :aria-label="$t('labels.actions.delete')"
                                            @click="handleDelete(label)"
                                        >
                                            <IconTrash class="size-4 text-rose-700" />
                                        </Button>
                                    </div>
                                </TableCell>
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

    <CreateDialog v-model:open="isCreateDialogOpen" />
    <EditDialog v-model:open="isEditDialogOpen" :label="editingLabel" />

    <ConfirmDeleteModal
        ref="deleteModal"
        :title="$t('labels.delete.title')"
        :description="$t('labels.delete.description')"
        :action="$t('labels.delete.confirm')"
        :cancel="$t('labels.delete.cancel')"
    />
</template>
