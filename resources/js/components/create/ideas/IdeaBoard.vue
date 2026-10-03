<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    type ComponentPublicInstance,
} from 'vue';

import IdeaCardView from '@/components/create/ideas/IdeaCard.vue';
import IdeaColumn from '@/components/create/ideas/IdeaColumn.vue';
import { useIdeaBoard } from '@/composables/useIdeaBoard';
import {
    columnKey,
    UNASSIGNED,
    type IdeaCard,
    type IdeaStage,
    type IdeaLabel,
} from '@/types/idea';

const props = defineProps<{
    stages: IdeaStage[];
    columns: Record<string, IdeaCard[]>;
    counts: Record<string, number>;
    labels: Map<string, IdeaLabel>;
    selectedIds: Set<string>;
    movable: boolean;
    newIdeaHref: (stageId: string | null) => string;
}>();

const emit = defineEmits<{
    moveIdea: [ideaId: string, toStageId: string | null, orderedIdeaIds: string[]];
    reorderStages: [orderedStageIds: string[]];
    createStage: [name: string];
    renameStage: [stage: IdeaStage, name: string];
    deleteStage: [stage: IdeaStage];
}>();

const {
    registerBoard,
    registerCard,
    registerColumn,
    registerColumnHandle,
    moveStage,
    cardPreview,
    stagePreview,
} = useIdeaBoard({
    onMoveIdea: (ideaId, toStageId, orderedIdeaIds) =>
        emit('moveIdea', ideaId, toStageId, orderedIdeaIds),
    onReorderStages: (orderedStageIds) =>
        emit('reorderStages', orderedStageIds),
});

const boardGroup = ref<ComponentPublicInstance | null>(null);
let stopBoard: (() => void) | null = null;

onMounted(() => {
    const board = boardGroup.value?.$el;

    if (board instanceof HTMLElement) {
        stopBoard = registerBoard(board);
    }
});

onBeforeUnmount(() => stopBoard?.());

const draggedCard = computed<IdeaCard | null>(() => {
    const ideaId = cardPreview.value?.ideaId;

    return ideaId
        ? (Object.values(props.columns)
              .flat()
              .find((card) => card.id === ideaId) ?? null)
        : null;
});

const draggedStage = computed<IdeaStage | null>(
    () =>
        props.stages.find(
            (stage) => stage.id === stagePreview.value?.stageId,
        ) ?? null,
);

type StageEntry =
    | { key: string; stage: IdeaStage }
    | { key: 'stage-placeholder'; stage: null };

const stageEntries = computed<StageEntry[]>(() => {
    const entries: StageEntry[] = props.stages.map((stage) => ({
        key: stage.id,
        stage,
    }));
    const preview = stagePreview.value;

    if (!preview) {
        return entries;
    }

    const anchor = entries.filter(
        (entry) => entry.stage?.id !== preview.stageId,
    )[preview.index];

    entries.splice(anchor ? entries.indexOf(anchor) : entries.length, 0, {
        key: 'stage-placeholder',
        stage: null,
    });

    return entries;
});

const addingStage = ref(false);
const newStageName = ref('');
const newStageInput = ref<HTMLInputElement | null>(null);
const newStageButton = ref<HTMLButtonElement | null>(null);

const startAddingStage = async (): Promise<void> => {
    newStageName.value = '';
    addingStage.value = true;
    await nextTick();
    newStageInput.value?.focus();
};

const cancelAddingStage = (): void => {
    addingStage.value = false;
    newStageName.value = '';
};

const cancelWithKeyboard = async (): Promise<void> => {
    cancelAddingStage();
    await nextTick();
    newStageButton.value?.focus();
};

const submitStage = (): void => {
    const name = newStageName.value.trim();

    if (!name) {
        cancelAddingStage();

        return;
    }

    emit('createStage', name);
    cancelAddingStage();
};
</script>

<template>
    <TransitionGroup
        ref="boardGroup"
        tag="div"
        move-class="transition-transform duration-200 ease-out motion-reduce:transition-none"
        class="relative flex min-h-0 flex-1 gap-4 overflow-x-auto overscroll-x-contain px-4 pt-4 pb-4 md:px-8"
        data-testid="ideas-board"
    >
        <IdeaColumn
            key="unassigned"
            :stage="null"
            :count="counts[UNASSIGNED] ?? 0"
            :cards="columns[UNASSIGNED] ?? []"
            :stages="stages"
            :labels="labels"
            :selected-ids="selectedIds"
            :movable="movable"
            :new-idea-href="newIdeaHref(null)"
            :card-preview="cardPreview"
            :dragged-card="draggedCard"
            :register-card="registerCard"
            :register-column="registerColumn"
            :register-column-handle="registerColumnHandle"
        />
        <template v-for="entry in stageEntries" :key="entry.key">
            <div
                v-if="entry.stage === null"
                aria-hidden="true"
                class="shrink-0 cursor-grabbing rounded-lg"
                :style="{
                    width: `${stagePreview?.width ?? 0}px`,
                    height: `${stagePreview?.height ?? 0}px`,
                }"
                data-testid="idea-stage-placeholder"
            >
                <div
                    v-if="draggedStage"
                    class="pointer-events-none flex h-full flex-col overflow-hidden rounded-lg bg-muted shadow-lg ring-1 ring-border"
                    data-testid="idea-stage-placeholder-preview"
                >
                    <div class="flex h-12 shrink-0 items-center gap-1 ps-3 pe-2 pt-2">
                        <span
                            class="truncate text-sm leading-5 font-emphasis text-foreground"
                            >{{ draggedStage.name }}</span
                        >
                        <span
                            class="inline-flex h-4.5 min-w-4.5 shrink-0 items-center justify-center rounded-full bg-secondary px-1 text-xs leading-[18px] font-medium text-muted-foreground"
                            >{{ counts[columnKey(draggedStage.id)] ?? 0 }}</span
                        >
                    </div>
                    <div class="flex flex-col gap-2 px-[9px] pt-1 pb-2">
                        <IdeaCardView
                            v-for="card in columns[columnKey(draggedStage.id)] ?? []"
                            :key="card.id"
                            :card="card"
                            view="board"
                            :stages="stages"
                            :labels="labels"
                            :selected="false"
                            :selecting="false"
                            preview
                        />
                    </div>
                </div>
            </div>
            <IdeaColumn
                v-else
                :class="{ 'hidden!': stagePreview?.stageId === entry.stage.id }"
                :stage="entry.stage"
                :count="counts[columnKey(entry.stage.id)] ?? 0"
                :cards="columns[columnKey(entry.stage.id)] ?? []"
                :stages="stages"
                :labels="labels"
                :selected-ids="selectedIds"
                :movable="movable"
                :new-idea-href="newIdeaHref(entry.stage.id)"
                :card-preview="cardPreview"
                :dragged-card="draggedCard"
                :register-card="registerCard"
                :register-column="registerColumn"
                :register-column-handle="registerColumnHandle"
                @rename="(name) => emit('renameStage', entry.stage, name)"
                @delete="emit('deleteStage', entry.stage)"
                @move-stage="(offset) => moveStage(entry.stage.id, offset)"
            />
        </template>

        <div
            key="new-stage"
            class="w-60 shrink-0"
            :class="
                addingStage
                    ? 'flex h-full flex-col rounded-lg bg-muted'
                    : 'self-start'
            "
            data-testid="idea-stage-new-slot"
        >
            <div
                v-if="addingStage"
                class="flex h-12 shrink-0 items-center ps-3 pe-2 pt-2"
            >
                <input
                    ref="newStageInput"
                    v-model="newStageName"
                    type="text"
                    class="h-7 min-w-0 flex-1 rounded-md border border-input bg-card px-2 text-sm font-emphasis text-foreground outline-none placeholder:text-subtle-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                    :placeholder="$t('create.ideas.new_stage_placeholder')"
                    :aria-label="$t('create.ideas.new_stage')"
                    data-testid="idea-stage-new-input"
                    @keydown.enter.prevent="submitStage"
                    @keydown.esc.prevent="cancelWithKeyboard"
                    @blur="submitStage"
                />
            </div>
            <button
                v-else
                ref="newStageButton"
                type="button"
                class="inline-flex min-h-12 w-full cursor-pointer items-center justify-center gap-1.5 rounded-xl bg-transparent px-4 py-3 text-sm font-medium whitespace-nowrap text-muted-foreground transition-control hover:bg-muted hover:text-foreground focus-visible:bg-muted focus-visible:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                data-testid="idea-stage-new"
                @click="startAddingStage"
            >
                <IconPlus class="size-4" />
                {{ $t('create.ideas.new_stage') }}
            </button>
        </div>
    </TransitionGroup>
</template>
