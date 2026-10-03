import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { disableNativeDragPreview } from '@atlaskit/pragmatic-drag-and-drop/element/disable-native-drag-preview';
import { reorder } from '@atlaskit/pragmatic-drag-and-drop/reorder';
import { onBeforeUnmount, ref, type Ref } from 'vue';

import {
    cancelDragOnEscape,
    createDragAutoScroller,
    placeholderIndexAt,
} from '@/lib/dragPlaceholder';

export interface IdeaBoardCardItem {
    ideaId: string;
    stageId: string | null;
}

export interface IdeaBoardColumnItem {
    stageId: string | null;
}

/**
 * The card being dragged and the slot it would land in: `index` within the
 * cards of `stageId`, not counting the dragged card. `over` is false while
 * the pointer is outside every column, when the slot falls back to where the
 * card came from and a drop changes nothing.
 */
export interface IdeaCardPreview {
    ideaId: string;
    stageId: string | null;
    index: number;
    height: number;
    over: boolean;
}

export interface IdeaStagePreview {
    stageId: string;
    index: number;
    width: number;
    height: number;
}

type DragData = Record<string | symbol, unknown>;
type Pointer = { clientX: number; clientY: number };

const CARD_KEY = 'ideaBoardCard';
const COLUMN_KEY = 'ideaBoardColumn';
const BOARD_KEY = 'ideaBoard';

const isCard = (data: DragData): data is DragData & IdeaBoardCardItem =>
    data[CARD_KEY] === true && typeof data.ideaId === 'string';

const isColumn = (data: DragData): data is DragData & IdeaBoardColumnItem =>
    data[COLUMN_KEY] === true && 'stageId' in data;

const isStageColumn = (
    data: DragData,
): data is DragData & { stageId: string } =>
    isColumn(data) && typeof data.stageId === 'string';

const byDocumentOrder = <T>([a]: [HTMLElement, T], [b]: [HTMLElement, T]): number =>
    a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;

/**
 * Drag-and-drop for the ideas board, in the same placeholder style as the
 * sidebar channels: no native drag image, the dragged card (or stage column)
 * collapses, and `cardPreview` / `stagePreview` hold the slot it would land
 * in, which the board renders as a lifted copy of the item.
 */
export const useIdeaBoard = (options: {
    onMoveIdea: (
        ideaId: string,
        toStageId: string | null,
        orderedIdeaIds: string[],
    ) => void;
    onReorderStages: (orderedStageIds: string[]) => void;
}): {
    registerBoard: (el: HTMLElement) => () => void;
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
    moveStage: (stageId: string, offset: -1 | 1) => void;
    cardPreview: Ref<IdeaCardPreview | null>;
    stagePreview: Ref<IdeaStagePreview | null>;
} => {
    const cardPreview = ref<IdeaCardPreview | null>(null);
    const stagePreview = ref<IdeaStagePreview | null>(null);
    const cards = new Map<HTMLElement, IdeaBoardCardItem>();
    const columns = new Map<HTMLElement, IdeaBoardColumnItem>();
    const lists = new Map<HTMLElement, HTMLElement>();
    let board: HTMLElement | null = null;
    let origin: { stageId: string | null; index: number } | null = null;
    let pointer: Pointer | null = null;
    let stopEscape: (() => void) | null = null;

    const columnIdeas = (stageId: string | null): string[] =>
        [...cards]
            .filter(([, item]) => item.stageId === stageId)
            .sort(byDocumentOrder)
            .map(([, item]) => item.ideaId);

    const stageOrder = (): string[] =>
        [...columns]
            .filter(([, column]) => column.stageId !== null)
            .sort(byDocumentOrder)
            .map(([, column]) => column.stageId as string);

    const columnUnder = (
        targets: { element: Element; data: DragData }[],
    ): HTMLElement | null => {
        const target = targets.find((candidate) => isColumn(candidate.data));

        return target?.element instanceof HTMLElement ? target.element : null;
    };

    const placeCard = (at: Pointer, column: HTMLElement | null): void => {
        const preview = cardPreview.value;
        const item = column ? columns.get(column) : undefined;
        const list = column ? lists.get(column) : undefined;

        if (!preview || !origin) {
            return;
        }

        if (!item || !list) {
            cardPreview.value = { ...preview, ...origin, over: false };

            return;
        }

        cardPreview.value = {
            ...preview,
            stageId: item.stageId,
            index: placeholderIndexAt(list, at, {
                attribute: 'sortableIdea',
                draggedId: preview.ideaId,
                axis: 'vertical',
            }),
            over: true,
        };
    };

    const placeStage = (at: Pointer | null): void => {
        const preview = stagePreview.value;

        if (!preview || !origin) {
            return;
        }

        stagePreview.value = {
            ...preview,
            index:
                board && at
                    ? placeholderIndexAt(board, at, {
                          attribute: 'sortableStage',
                          draggedId: preview.stageId,
                          axis: 'horizontal',
                      })
                    : origin.index,
        };
    };

    let hoveredColumn: HTMLElement | null = null;

    const followPointer = (): void => {
        if (!pointer) {
            return;
        }

        if (cardPreview.value) {
            placeCard(pointer, hoveredColumn);
        } else if (stagePreview.value) {
            placeStage(pointer);
        }
    };

    const boardScroller = createDragAutoScroller('horizontal', followPointer);
    const listScroller = createDragAutoScroller('vertical', followPointer);

    const endPreview = (): void => {
        boardScroller.stop();
        listScroller.stop();
        pointer = null;
        origin = null;
        hoveredColumn = null;
        cardPreview.value = null;
        stagePreview.value = null;
        stopEscape?.();
        stopEscape = null;
    };

    const startCardPreview = (item: IdeaBoardCardItem): void => {
        const element = [...cards].find(
            ([, candidate]) => candidate.ideaId === item.ideaId,
        )?.[0];
        const index = columnIdeas(item.stageId).indexOf(item.ideaId);

        if (!element || index === -1) {
            return;
        }

        origin = { stageId: item.stageId, index };
        cardPreview.value = {
            ideaId: item.ideaId,
            stageId: item.stageId,
            index,
            height: element.offsetHeight,
            over: false,
        };
        stopEscape = cancelDragOnEscape(endPreview);
    };

    const startStagePreview = (stageId: string): void => {
        const element = [...columns].find(
            ([, column]) => column.stageId === stageId,
        )?.[0];
        const index = stageOrder().indexOf(stageId);

        if (!element || index === -1) {
            return;
        }

        origin = { stageId, index };
        stagePreview.value = {
            stageId,
            index,
            width: element.offsetWidth,
            height: element.offsetHeight,
        };
        stopEscape = cancelDragOnEscape(endPreview);
    };

    const dropCard = (preview: IdeaCardPreview): void => {
        if (!preview.over) {
            return;
        }

        const current = columnIdeas(preview.stageId);
        const next = current.filter((id) => id !== preview.ideaId);
        next.splice(preview.index, 0, preview.ideaId);

        if (
            current.length === next.length &&
            current.every((id, position) => id === next[position])
        ) {
            return;
        }

        options.onMoveIdea(preview.ideaId, preview.stageId, next);
    };

    const dropStage = (preview: IdeaStagePreview): void => {
        const order = stageOrder();
        const startIndex = order.indexOf(preview.stageId);

        if (startIndex !== -1 && startIndex !== preview.index) {
            options.onReorderStages(
                reorder({ list: order, startIndex, finishIndex: preview.index }),
            );
        }
    };

    const stopMonitor = monitorForElements({
        canMonitor: ({ source }) =>
            isCard(source.data) || isStageColumn(source.data),
        onDragStart: ({ source }) => {
            if (isCard(source.data)) {
                startCardPreview({
                    ideaId: source.data.ideaId,
                    stageId: source.data.stageId,
                });
            } else if (isStageColumn(source.data)) {
                startStagePreview(source.data.stageId);
            }
        },
        onDrag: ({ location }) => {
            if (!cardPreview.value && !stagePreview.value) {
                return;
            }

            pointer = location.current.input;
            hoveredColumn = columnUnder(location.current.dropTargets);

            const overBoard = location.current.dropTargets.some(
                (target) => target.data[BOARD_KEY] === true,
            );

            if (cardPreview.value) {
                placeCard(pointer, hoveredColumn);
                listScroller.update(
                    hoveredColumn ? (lists.get(hoveredColumn) ?? null) : null,
                    pointer,
                );
            } else {
                placeStage(overBoard ? pointer : null);
            }

            boardScroller.update(overBoard ? board : null, pointer);
        },
        onDrop: ({ location }) => {
            const overBoard = location.current.dropTargets.some(
                (target) => target.data[BOARD_KEY] === true,
            );

            if (cardPreview.value) {
                placeCard(
                    location.current.input,
                    columnUnder(location.current.dropTargets),
                );
            } else if (overBoard) {
                placeStage(location.current.input);
            }

            const card = cardPreview.value;
            const stage = stagePreview.value;

            endPreview();

            if (card) {
                dropCard(card);
            } else if (stage && overBoard) {
                dropStage(stage);
            }
        },
    });

    onBeforeUnmount(() => {
        stopMonitor();
        endPreview();
    });

    const registerBoard = (el: HTMLElement): (() => void) => {
        board = el;

        const cleanup = dropTargetForElements({
            element: el,
            canDrop: ({ source }) =>
                isCard(source.data) || isStageColumn(source.data),
            getData: () => ({ [BOARD_KEY]: true }),
        });

        return () => {
            cleanup();

            if (board === el) {
                board = null;
            }
        };
    };

    const registerCard = (
        el: HTMLElement,
        item: IdeaBoardCardItem,
    ): (() => void) => {
        cards.set(el, item);
        el.dataset.sortableIdea = item.ideaId;

        const cleanup = draggable({
            element: el,
            getInitialData: () => ({ [CARD_KEY]: true, ...item }),
            onGenerateDragPreview: ({ nativeSetDragImage }) =>
                disableNativeDragPreview({ nativeSetDragImage }),
        });

        return () => {
            cards.delete(el);
            delete el.dataset.sortableIdea;
            cleanup();
        };
    };

    const registerColumn = (
        el: HTMLElement,
        list: HTMLElement,
        column: IdeaBoardColumnItem,
    ): (() => void) => {
        columns.set(el, column);
        lists.set(el, list);

        if (column.stageId !== null) {
            el.dataset.sortableStage = column.stageId;
        }

        const cleanup = dropTargetForElements({
            element: el,
            canDrop: ({ source }) => isCard(source.data),
            getData: () => ({ [COLUMN_KEY]: true, ...column }),
        });

        return () => {
            columns.delete(el);
            lists.delete(el);
            delete el.dataset.sortableStage;
            cleanup();
        };
    };

    const registerColumnHandle = (
        handle: HTMLElement,
        column: HTMLElement,
        item: { stageId: string },
    ): (() => void) =>
        draggable({
            element: column,
            dragHandle: handle,
            getInitialData: () => ({ [COLUMN_KEY]: true, ...item }),
            onGenerateDragPreview: ({ nativeSetDragImage }) =>
                disableNativeDragPreview({ nativeSetDragImage }),
        });

    const moveStage = (stageId: string, offset: -1 | 1): void => {
        const order = stageOrder();
        const startIndex = order.indexOf(stageId);
        const finishIndex = startIndex + offset;

        if (startIndex === -1 || finishIndex < 0 || finishIndex >= order.length) {
            return;
        }

        options.onReorderStages(reorder({ list: order, startIndex, finishIndex }));
    };

    return {
        registerBoard,
        registerCard,
        registerColumn,
        registerColumnHandle,
        moveStage,
        cardPreview,
        stagePreview,
    };
};
