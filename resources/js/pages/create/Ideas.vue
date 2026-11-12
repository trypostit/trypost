<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { IconBulb, IconPlus, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, provide, ref, shallowRef, watch } from 'vue';
import { toast } from 'vue-sonner';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import CreateHeader from '@/components/create/CreateHeader.vue';
import CreateTabs from '@/components/create/CreateTabs.vue';
import GenerateIdeasDialog from '@/components/create/ideas/GenerateIdeasDialog.vue';
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
    type IdeaDraft,
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
    hasData: boolean;
    labels: IdeaLabel[];
    filters: IdeaFilters;
    editor: IdeaEditorState | null;
    columns?: Record<string, IdeaCardPage>;
    ideas?: IdeaCardPage;
}>();

type Query = Record<string, string | string[] | undefined>;

const BOARD_PROPS = ['columns', 'stages', 'unassigned_count', 'hasData'];
const GALLERY_PROPS = ['ideas', 'stages', 'unassigned_count', 'hasData'];
const COUNT_PROPS = ['stages', 'unassigned_count'];

const columnPaths = (): string[] =>
    Object.keys(props.columns ?? {}).map((key) => `columns.${key}`);

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
            only: ['view', 'columns', 'ideas', 'filters', ...COUNT_PROPS],
            reset: ['ideas', ...columnPaths()],
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

const prefill = ref<IdeaDraft | null>(null);

watch(
    () => props.editor,
    (editor) => {
        if (editor?.mode !== 'create') {
            prefill.value = null;
        }
    },
);

const useGeneratedIdea = (idea: IdeaDraft): void => {
    prefill.value = idea;
    router.visit(newIdeaHref(), {
        preserveState: true,
        preserveScroll: true,
        only: ['editor'],
    });
};

const labelsById = computed(
    () => new Map(props.labels.map((label) => [label.id, label])),
);

const page = usePage();

const stageCards = (
    columns: Record<string, IdeaCardPage> = {},
): Record<string, IdeaCard[]> =>
    Object.fromEntries(
        Object.entries(columns).map(([key, column]) => [key, column.data]),
    );

const localColumns = shallowRef<Record<string, IdeaCard[]>>(
    stageCards(props.columns),
);
const localStages = shallowRef<IdeaStage[]>(props.stages);

const appendsPage = (key: string): boolean =>
    (page.scrollProps?.[`columns.${key}`]?.currentPage ?? 1) !== 1;

const syncColumns = (
    columns: Record<string, IdeaCardPage> = {},
    previous: Record<string, IdeaCardPage> = {},
): void => {
    const current = localColumns.value;
    const shownIds = new Set(
        Object.values(current)
            .flat()
            .map((card) => card.id),
    );

    localColumns.value = Object.fromEntries(
        Object.entries(columns).map(([key, column]) => {
            const shown = current[key];

            if (shown && previous[key] === column) {
                return [key, shown];
            }

            if (shown && appendsPage(key)) {
                return [
                    key,
                    [
                        ...shown,
                        ...column.data.filter((card) => !shownIds.has(card.id)),
                    ],
                ];
            }

            return [key, column.data];
        }),
    );
};

watch(() => props.columns, syncColumns);

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

const counts = computed<Record<string, number>>(() => ({
    [UNASSIGNED]: props.unassigned_count,
    ...Object.fromEntries(
        localStages.value.map((stage) => [stage.id, stage.ideas_count ?? 0]),
    ),
}));

const listReload = computed(() => ({
    only: props.view === 'board' ? BOARD_PROPS : GALLERY_PROPS,
    reset: props.view === 'gallery' ? ['ideas'] : columnPaths(),
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

    if (moving.value) {
        localColumns.value = next;
        queuedMove.value = { ideaId, toStageId, orderedIdeaIds };

        return;
    }

    const rollback = (message: string): void => {
        queuedMove.value = null;
        localColumns.value = snapshot;
        toast.error(message, { testId: 'ideas-move-error-toast' });
        router.reload({ only: BOARD_PROPS, reset: columnPaths() });
    };

    moving.value = true;
    localColumns.value = next;

    router.put(
        move.url(ideaId),
        {
            idea_stage_id: toStageId,
            after_idea_id:
                orderedIdeaIds[orderedIdeaIds.indexOf(ideaId) - 1] ?? null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: COUNT_PROPS,
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
                    errors.after_idea_id ??
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
            only: ['stages', 'columns'],
            reset: columnPaths(),
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

const deleteAndReload = (
    url: string,
    data: { idea_ids?: string[] } = {},
): Promise<void> =>
    new Promise((resolve, reject) => {
        router.delete(url, {
            data,
            preserveScroll: true,
            preserveState: true,
            ...listReload.value,
            onSuccess: () => resolve(),
            onError: () => reject(new Error('delete failed')),
            onFinish: (visit) => {
                if (visit.interrupted) {
                    resolve();
                } else if (!visit.completed) {
                    reject(new Error('delete failed'));
                }
            },
        });
    });

const deleteStage = (stage: IdeaStage): void => {
    const url = destroyStage.url(stage.id);

    stageDeleteModal.value?.open({
        url,
        request: () => deleteAndReload(url),
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
    const data = { idea_ids: [...selectedIds.value] };

    bulkDeleteModal.value?.open({
        url: bulkDestroy.url(),
        data,
        request: () => deleteAndReload(bulkDestroy.url(), data),
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
            request: () => deleteAndReload(destroy.url(card.id)),
        });
    },
});

const galleryCards = computed(() => props.ideas?.data ?? []);
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
                <GenerateIdeasDialog @use="useGeneratedIdea" />
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
                    hasData
                        ? $t('posts.no_search_results')
                        : $t('create.ideas.empty.title')
                "
                :description="
                    hasData
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
        :prefill="editor.mode === 'create' ? prefill : null"
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
