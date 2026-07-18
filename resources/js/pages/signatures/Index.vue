<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import { IconHash, IconPencil, IconSearch, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import CreateDialog from '@/components/signatures/CreateDialog.vue';
import EditDialog from '@/components/signatures/EditDialog.vue';
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
import { destroy as signaturesDestroy, index as signaturesIndex } from '@/routes/app/signatures';

interface Workspace {
    id: string;
    name: string;
}

interface Signature {
    id: string;
    name: string;
    content: string;
    created_at: string;
}

interface ScrollSignatures {
    data: Signature[];
    meta: { hasNextPage: boolean };
}

interface Props {
    workspace: Workspace;
    signatures: ScrollSignatures;
    filters: { search: string };
}

const props = defineProps<Props>();

const searchQuery = ref(props.filters.search);

const search = debounce(() => {
    router.get(
        signaturesIndex.url(),
        { search: searchQuery.value || undefined },
        { preserveState: true, preserveScroll: true, reset: ['signatures'] },
    );
}, 300);

watch(searchQuery, () => search());

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
const isCreateDialogOpen = ref(false);
const isEditDialogOpen = ref(false);
const editingSignature = ref<Signature | null>(null);

const openEditDialog = (signature: Signature) => {
    editingSignature.value = signature;
    isEditDialogOpen.value = true;
};

const handleDelete = (signature: Signature) => {
    deleteModal.value?.open({
        url: signaturesDestroy.url(signature.id),
        confirmText: signature.name,
    });
};

const formatDate = (date: string): string => dayjs.utc(date).local().format('D MMM YYYY');

const hasActiveSearch = computed(() => Boolean(searchQuery.value?.trim()));
</script>

<template>
    <Head :title="$t('signatures.title')" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <PageHeader :title="$t('signatures.title')" />

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative w-full sm:w-auto">
                    <IconSearch class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        v-model="searchQuery"
                        :placeholder="trans('signatures.search')"
                        class="w-full pl-9 sm:w-64"
                    />
                </div>

                <Button @click="isCreateDialogOpen = true">{{ $t('signatures.new') }}</Button>
            </div>

            <EmptyState
                v-if="signatures.data.length === 0"
                :icon="IconHash"
                :title="hasActiveSearch ? $t('signatures.no_search_results') : $t('signatures.empty_title')"
                :description="hasActiveSearch ? $t('signatures.try_different_search') : $t('signatures.empty_description')"
            />

            <div v-else>
                <InfiniteScroll data="signatures" items-element="#signatures-body" preserve-url>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ $t('signatures.table.name') }}</TableHead>
                                <TableHead>{{ $t('signatures.table.content') }}</TableHead>
                                <TableHead>{{ $t('signatures.table.created_at') }}</TableHead>
                                <TableHead class="text-right" />
                            </TableRow>
                        </TableHeader>
                        <TableBody id="signatures-body">
                            <TableRow
                                v-for="signature in signatures.data"
                                :key="signature.id"
                                class="cursor-pointer"
                                @click="openEditDialog(signature)"
                            >
                                <TableCell>{{ signature.name }}</TableCell>
                                <TableCell class="max-w-[160px] sm:max-w-md">
                                    <p class="truncate">{{ signature.content }}</p>
                                </TableCell>
                                <TableCell>{{ formatDate(signature.created_at) }}</TableCell>
                                <TableCell class="text-right" @click.stop>
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            class="size-8"
                                            :aria-label="$t('signatures.actions.edit')"
                                            @click="openEditDialog(signature)"
                                        >
                                            <IconPencil class="size-4" />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            class="size-8 bg-rose-100 hover:bg-rose-200"
                                            :aria-label="$t('signatures.actions.delete')"
                                            @click="handleDelete(signature)"
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
    <EditDialog v-model:open="isEditDialogOpen" :signature="editingSignature" />

    <ConfirmDeleteModal
        ref="deleteModal"
        :title="$t('signatures.delete.title')"
        :description="$t('signatures.delete.description')"
        :action="$t('signatures.delete.confirm')"
        :cancel="$t('signatures.delete.cancel')"
    />
</template>
