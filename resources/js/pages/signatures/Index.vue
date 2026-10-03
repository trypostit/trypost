<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import {
    IconDotsVertical,
    IconPencil,
    IconPlus,
    IconSignature,
    IconTrash,
} from '@tabler/icons-vue';
import { ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSearch from '@/components/settings/SettingsSearch.vue';
import CreateDialog from '@/components/signatures/CreateDialog.vue';
import EditDialog from '@/components/signatures/EditDialog.vue';
import SignaturesEmptyIllustration from '@/components/signatures/SignaturesEmptyIllustration.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableLoadMore } from '@/components/ui/table';
import debounce from '@/debounce';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import {
    destroy as signaturesDestroy,
    index as signaturesIndex,
} from '@/routes/app/signatures';

interface Workspace {
    id: string;
    name: string;
}

interface Signature {
    id: string;
    name: string;
    content: string;
}

interface ScrollSignatures {
    data: Signature[];
    meta: { hasNextPage: boolean };
}

interface Props {
    workspace: Workspace;
    signatures: ScrollSignatures;
    hasData: boolean;
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

const openCreateDialog = (): void => {
    isCreateDialogOpen.value = true;
};

const isEditDialogOpen = ref(false);
const editingSignature = ref<Signature | null>(null);

const openEditDialog = (signature: Signature) => {
    editingSignature.value = signature;
    isEditDialogOpen.value = true;
};

const handleDelete = (signature: Signature) => {
    deleteModal.value?.open({
        url: signaturesDestroy.url(signature.id),
    });
};

</script>

<template>
    <Head :title="$t('signatures.title')" />

    <SettingsLayout
        :title="$t('signatures.title')"
        :description="$t('signatures.description')"
        :centered="!hasData"
    >
        <template #actions>
            <Button
                data-testid="create-signature-button"
                :aria-label="$t('signatures.new')"
                @click="openCreateDialog"
            >
                <IconPlus class="size-4" />
                <span class="hidden sm:inline">{{ $t('signatures.new') }}</span>
            </Button>
        </template>

        <EmptyState
            v-if="!hasData"
            :title="$t('signatures.empty_title')"
            :description="$t('signatures.empty_description')"
            data-testid="signatures-empty"
        >
            <template #illustration>
                <SignaturesEmptyIllustration />
            </template>
            <template #action>
                <Button
                    data-testid="signatures-empty-create"
                    @click="openCreateDialog"
                >
                    <IconPlus class="size-4" />
                    {{ $t('signatures.create.title') }}
                </Button>
            </template>
        </EmptyState>

        <div v-else class="flex flex-col gap-3">
            <SettingsSearch
                v-model="searchQuery"
                :placeholder="$t('signatures.search')"
            />

            <EmptyState
                v-if="signatures.data.length === 0"
                :title="$t('signatures.no_search_results')"
                :description="$t('signatures.try_different_search')"
            >
                <template #illustration>
                    <SignaturesEmptyIllustration />
                </template>
            </EmptyState>

            <div v-else>
                <InfiniteScroll
                    data="signatures"
                    items-element="#signatures-body"
                    preserve-url
                >
                    <ul id="signatures-body" class="flex flex-col gap-2">
                        <SettingsListRow
                            v-for="signature in signatures.data"
                            :key="signature.id"
                            class="cursor-pointer"
                            :data-testid="`signature-row-${signature.id}`"
                            @click="openEditDialog(signature)"
                        >
                            <template #media>
                                <span
                                    class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary-text"
                                >
                                    <IconSignature class="size-5" />
                                </span>
                            </template>
                            <p
                                class="truncate text-sm leading-tight font-emphasis text-foreground"
                            >
                                {{ signature.name }}
                            </p>
                            <p
                                class="truncate text-sm text-muted-foreground"
                                :title="signature.content"
                                :data-testid="`signature-preview-${signature.id}`"
                            >
                                {{ signature.content }}
                            </p>
                            <template #actions>
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="shrink-0 text-muted-foreground data-[state=open]:bg-accent"
                                            :aria-label="$t('signatures.row_actions')"
                                            :data-testid="`signature-menu-${signature.id}`"
                                            @click.stop
                                        >
                                            <IconDotsVertical class="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" @click.stop>
                                        <DropdownMenuItem
                                            :data-testid="`edit-signature-${signature.id}`"
                                            @click="openEditDialog(signature)"
                                        >
                                            <IconPencil class="size-4" />
                                            {{ $t('signatures.actions.edit') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            :data-testid="`delete-signature-${signature.id}`"
                                            @click="handleDelete(signature)"
                                        >
                                            <IconTrash class="size-4" />
                                            {{ $t('signatures.actions.delete') }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </template>
                        </SettingsListRow>
                    </ul>

                    <template #next="{ loading }">
                        <TableLoadMore v-if="loading" />
                    </template>
                </InfiniteScroll>
            </div>
        </div>
    </SettingsLayout>

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
