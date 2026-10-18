<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import {
    IconPencil,
    IconPlus,
    IconTrash,
} from '@tabler/icons-vue';
import { ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import SavedRepliesEmptyIllustration from '@/components/saved-replies/SavedRepliesEmptyIllustration.vue';
import SavedReplyDialog from '@/components/saved-replies/SavedReplyDialog.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSearch from '@/components/settings/SettingsSearch.vue';
import { Button } from '@/components/ui/button';
import { TableLoadMore } from '@/components/ui/table';
import debounce from '@/debounce';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import type { SavedReply } from '@/lib/savedReplies';
import {
    destroy as savedRepliesDestroy,
    index as savedRepliesIndex,
} from '@/routes/app/saved-replies';

interface Props {
    savedReplies: { data: SavedReply[] };
    hasData: boolean;
    filters: { search: string };
}

const props = defineProps<Props>();

const searchQuery = ref(props.filters.search);

const search = debounce(() => {
    router.get(
        savedRepliesIndex.url(),
        { search: searchQuery.value || undefined },
        { preserveState: true, preserveScroll: true, reset: ['savedReplies'] },
    );
}, 300);

watch(searchQuery, () => search());

const isDialogOpen = ref(false);
const editingSavedReply = ref<SavedReply | null>(null);

const openCreateDialog = (): void => {
    editingSavedReply.value = null;
    isDialogOpen.value = true;
};

const openEditDialog = (savedReply: SavedReply): void => {
    editingSavedReply.value = savedReply;
    isDialogOpen.value = true;
};

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
const deletingName = ref('');

const confirmDelete = (savedReply: SavedReply): void => {
    deletingName.value = savedReply.name;
    deleteModal.value?.open({ url: savedRepliesDestroy.url(savedReply.id) });
};
</script>

<template>
    <Head :title="$t('saved_replies.title')" />

    <SettingsLayout
        :title="$t('saved_replies.title')"
        :description="$t('saved_replies.description')"
        :centered="!hasData"
    >
        <template #actions>
            <Button
                data-testid="create-saved-reply-button"
                :aria-label="$t('saved_replies.new')"
                @click="openCreateDialog"
            >
                <IconPlus class="size-4" />
                <span class="hidden sm:inline">{{ $t('saved_replies.new') }}</span>
            </Button>
        </template>

        <EmptyState
            v-if="!hasData"
            :title="$t('saved_replies.empty_title')"
            :description="$t('saved_replies.empty_description')"
            data-testid="saved-replies-empty"
        >
            <template #illustration>
                <SavedRepliesEmptyIllustration />
            </template>
            <template #action>
                <Button
                    data-testid="saved-replies-empty-create"
                    @click="openCreateDialog"
                >
                    <IconPlus class="size-4" />
                    {{ $t('saved_replies.new') }}
                </Button>
            </template>
        </EmptyState>

        <div v-else class="flex flex-col gap-3">
            <SettingsSearch
                v-model="searchQuery"
                :placeholder="$t('saved_replies.search')"
            />

            <EmptyState
                v-if="savedReplies.data.length === 0"
                :title="$t('saved_replies.no_search_results')"
                :description="$t('saved_replies.try_different_search')"
                data-testid="saved-replies-no-results"
            >
                <template #illustration>
                    <SavedRepliesEmptyIllustration />
                </template>
            </EmptyState>

            <InfiniteScroll
                v-else
                data="savedReplies"
                items-element="#saved-replies-body"
                preserve-url
            >
                <ul id="saved-replies-body" class="flex flex-col gap-2">
                    <SettingsListRow
                        v-for="savedReply in savedReplies.data"
                        :key="savedReply.id"
                        :data-testid="`saved-reply-row-${savedReply.id}`"
                    >
                        <p
                            class="truncate text-sm leading-tight font-emphasis text-foreground"
                        >
                            {{ savedReply.name }}
                        </p>
                        <p
                            class="line-clamp-2 text-sm break-words whitespace-pre-line text-muted-foreground"
                            :data-testid="`saved-reply-content-${savedReply.id}`"
                        >
                            {{ savedReply.content }}
                        </p>
                        <template #actions>
                            <div class="flex shrink-0 items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-muted-foreground"
                                    :aria-label="$t('saved_replies.actions.edit')"
                                    :title="$t('saved_replies.actions.edit')"
                                    :data-testid="`edit-saved-reply-${savedReply.id}`"
                                    @click="openEditDialog(savedReply)"
                                >
                                    <IconPencil class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive-text"
                                    :aria-label="$t('saved_replies.actions.delete')"
                                    :title="$t('saved_replies.actions.delete')"
                                    :data-testid="`delete-saved-reply-${savedReply.id}`"
                                    @click="confirmDelete(savedReply)"
                                >
                                    <IconTrash class="size-4" />
                                </Button>
                            </div>
                        </template>
                    </SettingsListRow>
                </ul>

                <template #next="{ loading }">
                    <TableLoadMore v-if="loading" />
                </template>
            </InfiniteScroll>
        </div>
    </SettingsLayout>

    <SavedReplyDialog
        v-model:open="isDialogOpen"
        :saved-reply="editingSavedReply"
    />

    <ConfirmDeleteModal
        ref="deleteModal"
        :title="$t('saved_replies.delete.title')"
        :description="$t('saved_replies.delete.description', { name: deletingName })"
        :action="$t('saved_replies.delete.confirm')"
        :cancel="$t('common.cancel')"
    />
</template>
