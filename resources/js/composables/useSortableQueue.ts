import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine';
import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { reorder } from '@atlaskit/pragmatic-drag-and-drop/reorder';
import { attachClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge/attach-closest-edge';
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge/extract-closest-edge';
import { getReorderDestinationIndex } from '@atlaskit/pragmatic-drag-and-drop-hitbox/util/get-reorder-destination-index';
import { onBeforeUnmount, ref, type Ref } from 'vue';

export interface SortableQueueItem {
    postId: string;
    channelId: string;
    queued: boolean;
}

export interface QueueSlotTarget {
    channelId: string;
    at: string;
}

export interface QueueDropIndicator {
    postId: string;
    edge: 'top' | 'bottom';
}

const ITEM_KEY = 'publishQueueItem';
const SLOT_KEY = 'publishQueueSlot';

const isQueueItem = (
    data: Record<string | symbol, unknown>,
): data is Record<string | symbol, unknown> & SortableQueueItem =>
    data[ITEM_KEY] === true &&
    typeof data.postId === 'string' &&
    typeof data.channelId === 'string';

const isSlotTarget = (
    data: Record<string | symbol, unknown>,
): data is Record<string | symbol, unknown> & QueueSlotTarget =>
    data[SLOT_KEY] === true &&
    typeof data.channelId === 'string' &&
    typeof data.at === 'string';

export const queueSlotKey = (slot: QueueSlotTarget): string =>
    `${slot.channelId}-${slot.at}`;

export const useSortableQueue = (options: {
    onReorder: (channelId: string, orderedPostIds: string[]) => void;
    onMoveToSlot: (postId: string, slot: QueueSlotTarget) => void;
}): {
    register: (el: HTMLElement, item: SortableQueueItem) => () => void;
    registerSlot: (el: HTMLElement, slot: QueueSlotTarget) => () => void;
    dropIndicator: Ref<QueueDropIndicator | null>;
    slotDropKey: Ref<string | null>;
} => {
    const dropIndicator = ref<QueueDropIndicator | null>(null);
    const slotDropKey = ref<string | null>(null);
    const elements = new Map<HTMLElement, SortableQueueItem>();

    const channelOrder = (channelId: string): string[] =>
        [...elements]
            .filter(([, item]) => item.queued && item.channelId === channelId)
            .sort(([a], [b]) =>
                a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING
                    ? -1
                    : 1,
            )
            .map(([, item]) => item.postId);

    const stopMonitor = monitorForElements({
        canMonitor: ({ source }) => isQueueItem(source.data),
        onDrop: ({ source, location }) => {
            dropIndicator.value = null;
            slotDropKey.value = null;

            const target = location.current.dropTargets[0];

            if (!target || !isQueueItem(source.data)) {
                return;
            }

            if (isSlotTarget(target.data)) {
                options.onMoveToSlot(source.data.postId, {
                    channelId: target.data.channelId,
                    at: target.data.at,
                });
                return;
            }

            if (!isQueueItem(target.data)) {
                return;
            }

            const { channelId, postId } = source.data;
            const order = channelOrder(channelId);
            const startIndex = order.indexOf(postId);
            const targetIndex = order.indexOf(target.data.postId);

            if (startIndex === -1 || targetIndex === -1) {
                return;
            }

            const finishIndex = getReorderDestinationIndex({
                startIndex,
                indexOfTarget: targetIndex,
                closestEdgeOfTarget: extractClosestEdge(target.data),
                axis: 'vertical',
            });

            if (finishIndex === startIndex) {
                return;
            }

            options.onReorder(
                channelId,
                reorder({ list: order, startIndex, finishIndex }),
            );
        },
    });

    onBeforeUnmount(stopMonitor);

    const showIndicator = (
        item: SortableQueueItem,
        data: Record<string | symbol, unknown>,
        sourceData: Record<string | symbol, unknown>,
    ): void => {
        const edge = extractClosestEdge(data);

        dropIndicator.value =
            edge && isQueueItem(sourceData) && sourceData.postId !== item.postId
                ? { postId: item.postId, edge: edge === 'bottom' ? 'bottom' : 'top' }
                : null;
    };

    const register = (el: HTMLElement, item: SortableQueueItem): (() => void) => {
        elements.set(el, item);

        const cleanup = combine(
            draggable({
                element: el,
                getInitialData: () => ({ [ITEM_KEY]: true, ...item }),
                onDragStart: () => el.setAttribute('data-dragging', ''),
                onDrop: () => el.removeAttribute('data-dragging'),
            }),
            dropTargetForElements({
                element: el,
                canDrop: ({ source }) =>
                    item.queued &&
                    isQueueItem(source.data) &&
                    source.data.queued &&
                    source.data.channelId === item.channelId,
                getData: ({ input }) =>
                    attachClosestEdge(
                        { [ITEM_KEY]: true, ...item },
                        { element: el, input, allowedEdges: ['top', 'bottom'] },
                    ),
                onDrag: ({ self, source }) =>
                    showIndicator(item, self.data, source.data),
                onDragLeave: () => {
                    if (dropIndicator.value?.postId === item.postId) {
                        dropIndicator.value = null;
                    }
                },
                onDrop: () => {
                    dropIndicator.value = null;
                },
            }),
        );

        return () => {
            elements.delete(el);
            cleanup();
        };
    };

    const registerSlot = (
        el: HTMLElement,
        slot: QueueSlotTarget,
    ): (() => void) => {
        const key = queueSlotKey(slot);
        const clear = (): void => {
            if (slotDropKey.value === key) {
                slotDropKey.value = null;
            }
        };

        return dropTargetForElements({
            element: el,
            canDrop: ({ source }) =>
                isQueueItem(source.data) &&
                source.data.channelId === slot.channelId,
            getData: () => ({ [SLOT_KEY]: true, ...slot }),
            onDragEnter: () => {
                slotDropKey.value = key;
            },
            onDragLeave: clear,
        });
    };

    return { register, registerSlot, dropIndicator, slotDropKey };
};
