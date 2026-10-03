<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconDots,
    IconGripHorizontal,
    IconPencil,
    IconPlus,
    IconTrash,
} from '@tabler/icons-vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    type ComponentPublicInstance,
    type Directive,
} from 'vue';

import IdeaCard from '@/components/create/ideas/IdeaCard.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    IdeaBoardCardItem,
    IdeaBoardColumnItem,
    IdeaCardPreview,
} from '@/composables/useIdeaBoard';
import {
    columnKey,
    type IdeaCard as IdeaCardData,
    type IdeaStage,
    type IdeaLabel,
} from '@/types/idea';

const props = defineProps<{
    stage: IdeaStage | null;
    count: number;
    cards: IdeaCardData[];
    stages: IdeaStage[];
    labels: Map<string, IdeaLabel>;
    selectedIds: Set<string>;
    movable: boolean;
    newIdeaHref: string;
    cardPreview: IdeaCardPreview | null;
    draggedCard: IdeaCardData | null;
    registerCard: (el: HTMLElement, item: IdeaBoardCardItem) => () => void;
    registerColumn: (
        el: HTMLElement,
        list: HTMLElement,
        column: IdeaBoardColumnItem,
    ) => () => void;
    registerColumnHandle: (
        handle: HTMLElement,
        column: HTMLElement,
        item: { stageId: string },
    ) => () => void;
}>();

const emit = defineEmits<{
    rename: [name: string];
    delete: [];
    moveStage: [offset: -1 | 1];
}>();

const key = columnKey(props.stage?.id ?? null);
const columnEl = ref<HTMLElement | null>(null);
const listGroup = ref<ComponentPublicInstance | null>(null);
const handleEl = ref<HTMLElement | null>(null);
const renameInput = ref<HTMLInputElement | null>(null);
const renaming = ref(false);
const draftName = ref('');
const cleanups: (() => void)[] = [];

onMounted(() => {
    const list = listGroup.value?.$el;

    if (!columnEl.value || !(list instanceof HTMLElement)) {
        return;
    }

    cleanups.push(
        props.registerColumn(columnEl.value, list, {
            stageId: props.stage?.id ?? null,
        }),
    );

    if (props.stage && handleEl.value) {
        cleanups.push(
            props.registerColumnHandle(handleEl.value, columnEl.value, {
                stageId: props.stage.id,
            }),
        );
    }
});

onBeforeUnmount(() => cleanups.forEach((cleanup) => cleanup()));

const cardCleanups = new WeakMap<HTMLElement, () => void>();

const unbindCard = (el: HTMLElement): void => {
    cardCleanups.get(el)?.();
    cardCleanups.delete(el);
};

const bindCard = (el: HTMLElement, item: IdeaBoardCardItem | null): void => {
    if (item) {
        cardCleanups.set(el, props.registerCard(el, item));
    }
};

const vIdeaCard: Directive<HTMLElement, IdeaBoardCardItem | null> = {
    mounted: (el, { value }) => bindCard(el, value),
    updated: (el, { value, oldValue }) => {
        if (
            value?.ideaId === oldValue?.ideaId &&
            value?.stageId === oldValue?.stageId
        ) {
            return;
        }

        unbindCard(el);
        bindCard(el, value);
    },
    unmounted: (el) => unbindCard(el),
};

const sortableItem = (card: IdeaCardData): IdeaBoardCardItem | null =>
    props.movable ? { ideaId: card.id, stageId: card.idea_stage_id } : null;

type CardEntry =
    | { key: string; card: IdeaCardData }
    | { key: 'placeholder'; card: null };

const isDropTarget = computed(
    () =>
        props.cardPreview?.over === true &&
        props.cardPreview.stageId === (props.stage?.id ?? null),
);

const entries = computed<CardEntry[]>(() => {
    const list: CardEntry[] = props.cards.map((card) => ({
        key: card.id,
        card,
    }));
    const preview = props.cardPreview;

    if (!preview || preview.stageId !== (props.stage?.id ?? null)) {
        return list;
    }

    const anchor = list.filter((entry) => entry.card?.id !== preview.ideaId)[
        preview.index
    ];

    list.splice(anchor ? list.indexOf(anchor) : list.length, 0, {
        key: 'placeholder',
        card: null,
    });

    return list;
});

const onHandleKeydown = (event: KeyboardEvent): void => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
        return;
    }

    event.preventDefault();

    const rtl = getComputedStyle(event.currentTarget as HTMLElement).direction === 'rtl';
    const forward = (event.key === 'ArrowRight') !== rtl;

    emit('moveStage', forward ? 1 : -1);
};

const startRename = async (): Promise<void> => {
    draftName.value = props.stage?.name ?? '';
    renaming.value = true;
    await nextTick();
    renameInput.value?.focus();
    renameInput.value?.select();
};

const finishRename = (save: boolean): void => {
    if (!renaming.value) {
        return;
    }

    renaming.value = false;
    const name = draftName.value.trim();

    if (save && name && name !== props.stage?.name) {
        emit('rename', name);
    }
};
</script>

<template>
    <section
        ref="columnEl"
        class="group/column relative flex h-full w-60 shrink-0 flex-col rounded-lg bg-muted transition-[background-color,box-shadow] duration-150 motion-reduce:transition-none"
        :class="isDropTarget ? 'bg-secondary ring-2 ring-primary-strong/40' : null"
        :data-drop-target="isDropTarget ? '' : undefined"
        :aria-label="stage ? stage.name : $t('create.ideas.unassigned')"
        :data-testid="`idea-column-${key}`"
    >
        <button
            v-if="stage"
            ref="handleEl"
            type="button"
            class="absolute top-0 left-1/2 flex h-4 w-8 -translate-x-1/2 cursor-grab items-center justify-center rounded-b-md text-muted-foreground opacity-0 transition-opacity duration-150 group-hover/column:opacity-100 focus-visible:opacity-100 active:cursor-grabbing"
            :aria-label="$t('create.ideas.reorder_stage', { stage: stage.name })"
            :data-testid="`idea-stage-handle-${stage.id}`"
            @keydown="onHandleKeydown"
        >
            <IconGripHorizontal class="size-4" />
        </button>

        <header class="flex h-12 shrink-0 items-center gap-1 ps-3 pe-2 pt-2">
            <input
                v-if="renaming"
                ref="renameInput"
                v-model="draftName"
                type="text"
                class="h-7 min-w-0 flex-1 rounded-md border border-input bg-card px-2 text-sm font-emphasis text-foreground outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :aria-label="$t('create.ideas.rename')"
                :data-testid="`idea-stage-rename-input-${key}`"
                @keydown.enter.prevent="finishRename(true)"
                @keydown.esc.prevent="finishRename(false)"
                @blur="finishRename(true)"
            />
            <template v-else>
                <h2
                    class="truncate text-sm leading-5 font-emphasis text-foreground"
                    :title="stage?.name"
                >
                    {{ stage ? stage.name : $t('create.ideas.unassigned') }}
                </h2>
                <span
                    class="inline-flex h-4.5 min-w-4.5 shrink-0 items-center justify-center rounded-full bg-secondary px-1 text-xs leading-[18px] font-medium text-muted-foreground"
                    :data-testid="`idea-column-count-${key}`"
                    >{{ count }}</span
                >
            </template>
            <span class="flex-1" />
            <Button
                variant="ghost"
                size="icon-xs"
                as-child
                class="shrink-0 hover:bg-secondary"
            >
                <Link
                    :href="newIdeaHref"
                    preserve-state
                    preserve-scroll
                    :only="['editor']"
                    :aria-label="$t('create.ideas.new')"
                    :data-testid="`idea-column-add-${key}`"
                >
                    <IconPlus class="size-4" />
                </Link>
            </Button>
            <DropdownMenu v-if="stage">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-xs"
                        class="shrink-0 hover:bg-secondary data-[state=open]:bg-secondary"
                        :aria-label="$t('create.ideas.more')"
                        :data-testid="`idea-column-menu-${stage.id}`"
                    >
                        <IconDots class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    class="w-64"
                    @close-auto-focus.prevent
                >
                    <DropdownMenuItem
                        :data-testid="`idea-stage-rename-${stage.id}`"
                        @click="startRename"
                    >
                        <IconPencil class="size-4" />
                        {{ $t('create.ideas.rename') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        class="items-start"
                        :data-testid="`idea-stage-delete-${stage.id}`"
                        @click="emit('delete')"
                    >
                        <IconTrash class="mt-px size-4" />
                        <span class="flex flex-col gap-0.5">
                            <span>{{ $t('create.ideas.delete_stage') }}</span>
                            <span
                                class="text-xs leading-4 font-normal text-muted-foreground"
                                >{{ $t('create.ideas.delete_stage_hint') }}</span
                            >
                        </span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </header>

        <TransitionGroup
            ref="listGroup"
            tag="div"
            move-class="transition-transform duration-200 ease-out motion-reduce:transition-none"
            class="relative flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-[9px] pt-1 pb-2"
            :data-testid="`idea-column-list-${key}`"
        >
            <template v-for="entry in entries" :key="entry.key">
                <div
                    v-if="entry.card === null"
                    aria-hidden="true"
                    class="shrink-0 cursor-grabbing rounded-lg"
                    :class="
                        draggedCard
                            ? null
                            : 'border border-dashed border-border bg-secondary'
                    "
                    :style="{ height: `${cardPreview?.height ?? 0}px` }"
                    data-testid="idea-card-placeholder"
                >
                    <div
                        v-if="draggedCard"
                        class="pointer-events-none rounded-lg shadow-md ring-1 ring-border"
                    >
                        <IdeaCard
                            :card="draggedCard"
                            view="board"
                            :stages="stages"
                            :labels="labels"
                            :selected="false"
                            :selecting="false"
                            preview
                        />
                    </div>
                </div>
                <div
                    v-else
                    v-idea-card="sortableItem(entry.card)"
                    class="relative"
                    :class="{ hidden: cardPreview?.ideaId === entry.card.id }"
                >
                    <IdeaCard
                        :card="entry.card"
                        view="board"
                        :stages="stages"
                        :labels="labels"
                        :selected="selectedIds.has(entry.card.id)"
                        :selecting="selectedIds.size > 0"
                    />
                </div>
            </template>

            <Link
                key="new"
                :href="newIdeaHref"
                preserve-state
                preserve-scroll
                :only="['editor']"
                class="flex h-8 shrink-0 items-center justify-center gap-1 rounded-lg px-2 text-sm font-medium text-muted-foreground transition-control hover:bg-secondary hover:text-foreground"
                :data-testid="`idea-column-new-${key}`"
            >
                <IconPlus class="size-4" />
                {{ $t('create.ideas.new') }}
            </Link>
        </TransitionGroup>
    </section>
</template>
