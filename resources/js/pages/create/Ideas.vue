<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { IconBulb, IconPlus, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, provide, ref, shallowRef, watch } from 'vue';
import { toast } from 'vue-sonner';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import CreateHeader from '@/components/create/CreateHeader.vue';
import CreateTabs from '@/components/create/CreateTabs.vue';
import GenerateIdeasPopover from '@/components/create/ideas/GenerateIdeasPopover.vue';
import IdeaBoard from '@/components/create/ideas/IdeaBoard.vue';
import IdeaEditorDialog from '@/components/create/ideas/IdeaEditorDialog.vue';
import IdeaGallery from '@/components/create/ideas/IdeaGallery.vue';
import IdeasToolbar from '@/components/create/ideas/IdeasToolbar.vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    destroy as destroyStage,
    reorder as reorderStages,
    store as storeStage,
    update as updateStage,
} from '@/routes/app/create/idea-stages';
import {
    bulkDestroy,
    create,
    destroy,
    duplicate,
    index,
    move,
    show,
    update,
} from '@/routes/app/create/ideas';
import {
    columnKey,
    ideaCardActionsKey,
    UNASSIGNED,
    type IdeaCard,
    type IdeaCardPage,
    type IdeaEditorState,
    type IdeaFilters,
    type IdeaStage,
    type IdeaLabel,
    type IdeasView,
} from '@/types/idea';

const props = defineProps<{
    view: IdeasView;
    stages: IdeaStage[];
    unassigned_count: number;
    labels: IdeaLabel[];
    filters: IdeaFilters;
    editor: IdeaEditorState | null;
    board?: IdeaCard[];
    ideas?: IdeaCardPage;
}>();

type Query = Record<string, string | string[] | undefined>;

const BOARD_PROPS = ['board', 'stages', 'unassigned_count'];
const GALLERY_PROPS = ['ideas', 'stages', 'unassigned_count'];

const selectedIds = ref<Set<string>>(new Set());

const clearSelection = (): void => {
    selectedIds.value = new Set();
};

const selectedStageIds = ref<string[]>([...props.filters.stages]);
const selectedLabelIds = ref<string[]>([...props.filters.labels]);
const untagged = ref<boolean>(props.filters.untagged);
const unassigned = ref<boolean>(props.filters.unassigned);

const query = (view: IdeasView = props.view): Query => ({
    view: view === 'gallery' ? 'gallery' : undefined,
    stages:
        view === 'gallery' && selectedStageIds.value.length
            ? selectedStageIds.value
            : undefined,
    labels: selectedLabelIds.value.length ? selectedLabelIds.value : undefined,
    untagged: untagged.value ? '1' : undefined,
    unassigned: view === 'gallery' && unassigned.value ? '1' : undefined,
});

const visitList = (view: IdeasView): void => {
    router.get(
        index.url({ query: query(view) }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['view', 'board', 'ideas', 'filters'],
            reset: ['ideas'],
        },
    );
};

watch([selectedStageIds, unassigned, selectedLabelIds, untagged], () => {
    clearSelection();
    visitList(props.view);
});

const changeView = (view: IdeasView): void => visitList(view);

const closeUrl = computed(() => index.url({ query: query() }));

const editorKey = computed(() =>
    props.editor?.mode === 'edit' ? props.editor.idea.id : 'create',
);

const newIdeaHref = (stageId: string | null = null): string =>
    create.url({ query: { ...query(), stage: stageId ?? undefined } });

const labelsById = computed(
    () => new Map(props.labels.map((label) => [label.id, label])),
);

const stageCards = (cards: IdeaCard[]): Record<string, IdeaCard[]> => {
    const columns: Record<string, IdeaCard[]> = { [UNASSIGNED]: [] };

    for (const card of cards) {
        const key = columnKey(card.idea_stage_id);
        columns[key] = [...(columns[key] ?? []), card];
    }

    return columns;
};

const localColumns = shallowRef<Record<string, IdeaCard[]>>(
    stageCards(props.board ?? []),
);
const localStages = shallowRef<IdeaStage[]>(props.stages);

watch(
    () => props.board,
    (board) => {
        localColumns.value = stageCards(board ?? []);
    },
);

watch(
    () => props.stages,
    (stages) => {
        localStages.value = stages;

        const existing = selectedStageIds.value.filter((id) =>
            stages.some((stage) => stage.id === id),
        );

        if (existing.length !== selectedStageIds.value.length) {
            selectedStageIds.value = existing;
        }
    },
);

const isBoardFiltered = computed(
    () => selectedLabelIds.value.length > 0 || untagged.value,
);

const movable = computed(
    () => props.view === 'board' && !isBoardFiltered.value,
);

const counts = computed<Record<string, number>>(() => {
    if (!isBoardFiltered.value) {
        return Object.fromEntries(
            [UNASSIGNED, ...localStages.value.map((stage) => stage.id)].map(
                (key) => [key, localColumns.value[key]?.length ?? 0],
            ),
        );
    }

    return {
        [UNASSIGNED]: props.unassigned_count,
        ...Object.fromEntries(
            localStages.value.map((stage) => [stage.id, stage.ideas_count ?? 0]),
        ),
    };
});

const listReload = computed(() => ({
    only: props.view === 'board' ? BOARD_PROPS : GALLERY_PROPS,
    reset: props.view === 'gallery' ? ['ideas'] : [],
}));

const moving = ref(false);
const queuedMove = ref<{
    ideaId: string;
    toStageId: string | null;
    orderedIdeaIds: string[];
} | null>(null);

const moveIdea = (
    ideaId: string,
    toStageId: string | null,
    orderedIdeaIds: string[],
): void => {
    const snapshot = localColumns.value;
    const cards = new Map(
        Object.values(snapshot)
            .flat()
            .map((card) => [card.id, card]),
    );
    const card = cards.get(ideaId);

    if (moving.value) {
        queuedMove.value = { ideaId, toStageId, orderedIdeaIds };

        return;
    }

    if (!card) {
        return;
    }

    const fromKey = columnKey(card.idea_stage_id);
    const toKey = columnKey(toStageId);
    const next = {
        ...snapshot,
        [fromKey]: (snapshot[fromKey] ?? []).filter(
            (candidate) => candidate.id !== ideaId,
        ),
    };

    cards.set(ideaId, { ...card, idea_stage_id: toStageId });
    next[toKey] = orderedIdeaIds
        .map((id) => cards.get(id))
        .filter((candidate): candidate is IdeaCard => candidate !== undefined);

    const rollback = (message: string): void => {
        queuedMove.value = null;
        localColumns.value = snapshot;
        toast.error(message, { testId: 'ideas-move-error-toast' });
        router.reload({ only: BOARD_PROPS });
    };

    moving.value = true;
    localColumns.value = next;

    router.put(
        move.url(ideaId),
        { idea_stage_id: toStageId, idea_ids: orderedIdeaIds },
        {
            preserveScroll: true,
            preserveState: true,
            only: BOARD_PROPS,
            onFinish: () => {
                moving.value = false;

                const queued = queuedMove.value;
                queuedMove.value = null;

                if (queued) {
                    moveIdea(
                        queued.ideaId,
                        queued.toStageId,
                        queued.orderedIdeaIds,
                    );
                }
            },
            onError: (errors) =>
                rollback(
                    errors.idea_ids ??
                        Object.values(errors)[0] ??
                        trans('create.ideas.errors.move_failed'),
                ),
            onHttpException: () => {
                rollback(trans('create.ideas.errors.move_failed'));

                return false;
            },
        },
    );
};

const reorderColumns = (orderedStageIds: string[]): void => {
    const snapshot = localStages.value;
    const byId = new Map(snapshot.map((stage) => [stage.id, stage]));

    const rollback = (message: string): void => {
        localStages.value = snapshot;
        toast.error(message, { testId: 'ideas-move-error-toast' });
        router.reload({ only: ['stages'] });
    };

    localStages.value = orderedStageIds
        .map((id) => byId.get(id))
        .filter((stage): stage is IdeaStage => stage !== undefined);

    router.put(
        reorderStages.url(),
        { stage_ids: orderedStageIds },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['stages'],
            onError: (errors) =>
                rollback(
                    errors.stage_ids ??
                        Object.values(errors)[0] ??
                        trans('create.ideas.errors.move_failed'),
                ),
            onHttpException: () => {
                rollback(trans('create.ideas.errors.move_failed'));

                return false;
            },
        },
    );
};

const toastFirstError = (errors: Record<string, string>): void => {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
};

const createStage = (name: string): void => {
    router.post(
        storeStage.url(),
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['stages', 'board'],
            onError: toastFirstError,
        },
    );
};

const renameStage = (stage: IdeaStage, name: string): void => {
    localStages.value = localStages.value.map((candidate) =>
        candidate.id === stage.id ? { ...candidate, name } : candidate,
    );

    router.put(
        updateStage.url(stage.id),
        { name },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['stages'],
            onError: (errors) => {
                localStages.value = props.stages;
                toastFirstError(errors);
            },
        },
    );
};

const stageDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);
const ideaDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);
const bulkDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);

const deleteStage = (stage: IdeaStage): void => {
    stageDeleteModal.value?.open({
        url: destroyStage.url(stage.id),
    });
};

watch(() => props.view, clearSelection);

const pendingDeleteId = ref<string | null>(null);

const onIdeaDeleted = (): void => {
    if (pendingDeleteId.value && selectedIds.value.has(pendingDeleteId.value)) {
        const next = new Set(selectedIds.value);
        next.delete(pendingDeleteId.value);
        selectedIds.value = next;
    }

    pendingDeleteId.value = null;
};

const openBulkDelete = (): void => {
    bulkDeleteModal.value?.open({
        url: bulkDestroy.url(),
        data: { idea_ids: [...selectedIds.value] },
    });
};

provide(ideaCardActionsKey, {
    open: (card) => {
        router.visit(show.url(card.id, { query: query() }), {
            preserveState: true,
            preserveScroll: true,
            only: ['editor'],
        });
    },
    toggle: (card) => {
        const next = new Set(selectedIds.value);

        if (next.has(card.id)) {
            next.delete(card.id);
        } else {
            next.add(card.id);
        }

        selectedIds.value = next;
    },
    select: (card) => {
        selectedIds.value = new Set([...selectedIds.value, card.id]);
    },
    move: (card, stageId) => {
        if (card.idea_stage_id === stageId) {
            return;
        }

        router.put(
            update.url(card.id),
            { idea_stage_id: stageId },
            {
                preserveScroll: true,
                preserveState: true,
                ...listReload.value,
                onError: toastFirstError,
            },
        );
    },
    duplicate: (card) => {
        router.post(
            duplicate.url(card.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                ...listReload.value,
            },
        );
    },
    remove: (card) => {
        pendingDeleteId.value = card.id;
        ideaDeleteModal.value?.open({
            url: destroy.url(card.id),
        });
    },
});

const galleryCards = computed(() => props.ideas?.data ?? []);

const hasGalleryFilters = computed(
    () => isBoardFiltered.value || selectedStageIds.value.length > 0,
);
</script>

<template>
    <Head :title="$t('create.ideas.title')" />

    <AppLayout full-width>
        <template #header>
            <CreateHeader />
        </template>

        <template #header-actions>
            <div
                v-if="selectedIds.size > 0"
                class="flex items-center gap-2"
                data-testid="ideas-bulk-bar"
            >
                <Button
                    variant="outline"
                    data-testid="ideas-clear-selection"
                    @click="clearSelection"
                >
                    {{ $t('create.ideas.clear_selection') }}
                </Button>
                <Button
                    variant="destructive"
                    data-testid="ideas-bulk-delete"
                    @click="openBulkDelete"
                >
                    <IconTrash class="size-4" />
                    {{
                        $tChoice('create.ideas.delete_selected', selectedIds.size, {
                            count: String(selectedIds.size),
                        })
                    }}
                </Button>
            </div>
            <div v-else class="flex items-center gap-2">
                <GenerateIdeasPopover :stages="localStages" />
                <Button
                    variant="outline"
                    as-child
                    class="max-sm:w-8 max-sm:px-0"
                >
                    <Link
                        :href="newIdeaHref()"
                        preserve-state
                        preserve-scroll
                        :only="['editor']"
                        :aria-label="$t('create.ideas.new')"
                        data-testid="ideas-new"
                    >
                        <IconPlus class="size-4" />
                        <span class="max-sm:sr-only">{{
                            $t('create.ideas.new')
                        }}</span>
                    </Link>
                </Button>
            </div>
        </template>

        <div
            class="flex min-h-0 flex-1 flex-col overflow-hidden"
            data-testid="ideas-page"
        >
            <CreateTabs active="ideas">
                <template #filters>
                    <IdeasToolbar
                        v-model:stage-ids="selectedStageIds"
                        v-model:label-ids="selectedLabelIds"
                        v-model:untagged="untagged"
                        v-model:unassigned="unassigned"
                        :view="view"
                        :stages="localStages"
                        :labels="labels"
                        @change-view="changeView"
                    />
                </template>
            </CreateTabs>

            <p
                v-if="view === 'board' && isBoardFiltered"
                class="px-4 pt-3 text-xs text-muted-foreground md:px-8"
                data-testid="ideas-drag-disabled-hint"
            >
                {{ $t('create.ideas.drag_disabled_hint') }}
            </p>

            <IdeaBoard
                v-if="view === 'board'"
                :stages="localStages"
                :columns="localColumns"
                :counts="counts"
                :labels="labelsById"
                :selected-ids="selectedIds"
                :movable="movable"
                :new-idea-href="newIdeaHref"
                @move-idea="moveIdea"
                @reorder-stages="reorderColumns"
                @create-stage="createStage"
                @rename-stage="renameStage"
                @delete-stage="deleteStage"
            />

            <EmptyState
                v-else-if="galleryCards.length === 0"
                :icon="IconBulb"
                :title="
                    hasGalleryFilters
                        ? $t('posts.no_search_results')
                        : $t('create.ideas.empty.title')
                "
                :description="
                    hasGalleryFilters
                        ? $t('posts.try_different_search')
                        : $t('create.ideas.empty.body')
                "
            />

            <IdeaGallery
                v-else
                :cards="galleryCards"
                :stages="localStages"
                :labels="labelsById"
                :selected-ids="selectedIds"
            />
        </div>
    </AppLayout>

    <IdeaEditorDialog
        v-if="editor"
        :key="editorKey"
        :editor="editor"
        :stages="localStages"
        :labels="labels"
        :close-url="closeUrl"
        :list-reload="listReload"
    />

    <ConfirmDeleteModal
        ref="stageDeleteModal"
        :title="$t('create.ideas.delete_stage_title')"
        :description="$t('create.ideas.delete_stage_body')"
        :action="$t('create.ideas.delete_stage')"
        :cancel="$t('create.ideas.cancel')"
    />

    <ConfirmDeleteModal
        ref="ideaDeleteModal"
        :title="$t('create.ideas.delete_confirm_title')"
        :description="$t('create.ideas.delete_confirm_body')"
        :action="$t('create.ideas.delete')"
        :cancel="$t('create.ideas.cancel')"
        @deleted="onIdeaDeleted"
    />

    <ConfirmDeleteModal
        ref="bulkDeleteModal"
        :title="
            $tChoice('create.ideas.delete_selected_title', selectedIds.size, {
                count: String(selectedIds.size),
            })
        "
        :description="$t('create.ideas.delete_selected_body')"
        :action="
            $tChoice('create.ideas.delete_selected', selectedIds.size, {
                count: String(selectedIds.size),
            })
        "
        :cancel="$t('create.ideas.cancel')"
        action-test-id="ideas-bulk-delete-confirm"
        @deleted="clearSelection"
    />
</template>
