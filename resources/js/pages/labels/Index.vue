<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import {
    IconDotsVertical,
    IconLayoutGrid,
    IconPlus,
    IconSettings,
    IconTrash,
    IconTrendingUp,
} from '@tabler/icons-vue';
import { ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import CreateDialog from '@/components/labels/CreateDialog.vue';
import EditDialog from '@/components/labels/EditDialog.vue';
import LabelsEmptyIllustration from '@/components/labels/LabelsEmptyIllustration.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSearch from '@/components/settings/SettingsSearch.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableLoadMore } from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import debounce from '@/debounce';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { formatNumberCompact } from '@/lib/utils';
import { insights as insightsRoute } from '@/routes/app';
import {
    destroy as labelsDestroy,
    index as labelsIndex,
} from '@/routes/app/labels';
import { index as postsIndex } from '@/routes/app/posts';

interface Label {
    id: string;
    name: string;
    color: string;
    posts_count: number;
}

interface ScrollLabels {
    data: Label[];
    meta: { hasNextPage: boolean };
}

interface Props {
    labels: ScrollLabels;
    hasData: boolean;
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

const openCreateDialog = (): void => {
    isCreateDialogOpen.value = true;
};

const openEditDialog = (label: Label) => {
    editingLabel.value = label;
    isEditDialogOpen.value = true;
};

const handleDelete = (label: Label) => {
    deleteModal.value?.open({
        url: labelsDestroy.url(label.id),
    });
};

const labelQuery = (label: Label) => ({ query: { labels: [label.id] } });


</script>

<template>
    <Head :title="$t('labels.title')" />

    <SettingsLayout
        :title="$t('labels.title')"
        :centered="!hasData"
        :description="$t('labels.description')"
    >
        <template #actions>
            <Button
                data-testid="create-label-button"
                :aria-label="$t('labels.new_label')"
                @click="openCreateDialog"
            >
                <IconPlus class="size-4" />
                <span class="hidden sm:inline">{{ $t('labels.new_label') }}</span>
            </Button>
        </template>

        <EmptyState
            v-if="!hasData"
            :title="$t('labels.no_labels_yet')"
            :description="$t('labels.description')"
            data-testid="labels-empty"
        >
            <template #illustration>
                <LabelsEmptyIllustration />
            </template>
            <template #action>
                <Button
                    data-testid="labels-empty-create"
                    @click="openCreateDialog"
                >
                    <IconPlus class="size-4" />
                    {{ $t('labels.create.title') }}
                </Button>
            </template>
        </EmptyState>

        <div v-else class="flex flex-col gap-3">
            <SettingsSearch
                v-model="searchQuery"
                :placeholder="$t('labels.search')"
            />

            <EmptyState
                v-if="labels.data.length === 0"
                :title="$t('labels.no_search_results')"
                :description="$t('labels.try_different_search')"
            >
                <template #illustration>
                    <LabelsEmptyIllustration />
                </template>
            </EmptyState>


            <div v-else>
                <InfiniteScroll
                    data="labels"
                    items-element="#labels-body"
                    preserve-url
                >
                    <ul id="labels-body" class="flex flex-col gap-2">
                        <SettingsListRow
                            v-for="label in labels.data"
                            :key="label.id"
                            :data-testid="`label-row-${label.id}`"
                        >
                            <template #media>
                                <span
                                    class="size-4 shrink-0 rounded-full"
                                    :style="{ backgroundColor: label.color }"
                                    aria-hidden="true"
                                    :data-testid="`label-swatch-${label.id}`"
                                />
                            </template>
                            <div class="flex min-w-0 items-center gap-2">
                                <p
                                    class="min-w-0 truncate text-sm leading-tight font-emphasis text-foreground"
                                >
                                    {{ label.name }}
                                </p>
                                <span
                                    class="inline-flex h-5 shrink-0 items-center rounded-full bg-secondary px-2 text-xs font-medium text-muted-foreground tabular-nums"
                                    :data-testid="`label-posts-count-${label.id}`"
                                    >{{
                                        $tChoice('labels.meta.posts', label.posts_count, {
                                            count: formatNumberCompact(label.posts_count),
                                        })
                                    }}</span
                                >
                            </div>
                            <template #actions>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="shrink-0 text-muted-foreground"
                                            :aria-label="$t('labels.actions.settings')"
                                            :data-testid="`label-settings-${label.id}`"
                                            @click="openEditDialog(label)"
                                        >
                                            <IconSettings class="size-4" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        :data-testid="`label-settings-tooltip-${label.id}`"
                                    >
                                        {{ $t('labels.actions.settings') }}
                                    </TooltipContent>
                                </Tooltip>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span class="inline-flex">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        class="shrink-0 text-muted-foreground data-[state=open]:bg-accent"
                                                        :aria-label="$t('labels.actions.more')"
                                                        :data-testid="`label-menu-${label.id}`"
                                                    >
                                                        <IconDotsVertical class="size-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem as-child>
                                                        <a
                                                            :href="postsIndex.url(labelQuery(label))"
                                                            target="_blank"
                                                            rel="noopener"
                                                            :data-testid="`label-view-posts-${label.id}`"
                                                        >
                                                            <IconLayoutGrid class="size-4" />
                                                            {{ $t('labels.actions.view_posts') }}
                                                        </a>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem as-child>
                                                        <a
                                                            :href="insightsRoute.url(labelQuery(label))"
                                                            target="_blank"
                                                            rel="noopener"
                                                            :data-testid="`label-view-insights-${label.id}`"
                                                        >
                                                            <IconTrendingUp class="size-4" />
                                                            {{ $t('labels.actions.view_insights') }}
                                                        </a>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        :data-testid="`delete-label-${label.id}`"
                                                        @click="handleDelete(label)"
                                                    >
                                                        <IconTrash class="size-4" />
                                                        {{ $t('labels.actions.delete') }}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent
                                        :data-testid="`label-menu-tooltip-${label.id}`"
                                    >
                                        {{ $t('labels.actions.more') }}
                                    </TooltipContent>
                                </Tooltip>
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
    <EditDialog v-model:open="isEditDialogOpen" :label="editingLabel" />

    <ConfirmDeleteModal
        ref="deleteModal"
        :title="$t('labels.delete.title')"
        :description="$t('labels.delete.description')"
        :action="$t('labels.delete.confirm')"
        :cancel="$t('labels.delete.cancel')"
    />
</template>
