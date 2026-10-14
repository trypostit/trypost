import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { disableNativeDragPreview } from '@atlaskit/pragmatic-drag-and-drop/element/disable-native-drag-preview';
import { reorder } from '@atlaskit/pragmatic-drag-and-drop/reorder';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { nextTick, onBeforeUnmount, ref, type Directive, type Ref } from 'vue';
import { toast } from 'vue-sonner';

import {
    cancelDragOnEscape,
    createDragAutoScroller,
    placeholderIndexAt,
} from '@/lib/dragPlaceholder';
import { reorder as reorderChannels } from '@/routes/app/channels';

export interface ChannelDragPreview {
    channelId: string;
    index: number;
    height: number;
}

const ITEM_KEY = 'sortableChannel';
const LIST_KEY = 'sortableChannelList';
const CHANNEL_ORDER_PROPS = ['channels', 'connectedChannels'];

const optimisticOrder = ref<string[] | null>(null);
const saving = ref(false);
let queuedOrder: string[] | null = null;

const isChannelItem = (
    data: Record<string | symbol, unknown>,
    list: string,
): data is Record<string | symbol, unknown> & { channelId: string } =>
    data[ITEM_KEY] === list && typeof data.channelId === 'string';

const isListTarget = (
    data: Record<string | symbol, unknown>,
    list: string,
): boolean => data[LIST_KEY] === list;

export const orderChannels = <T extends { id: string }>(items: T[]): T[] => {
    const order = optimisticOrder.value;

    if (!order) {
        return items;
    }

    const index = new Map(order.map((id, position) => [id, position]));

    return [...items].sort(
        (a, b) =>
            (index.get(a.id) ?? Number.MAX_SAFE_INTEGER) -
            (index.get(b.id) ?? Number.MAX_SAFE_INTEGER),
    );
};

const saveChannelOrder = (orderedIds: string[]): void => {
    optimisticOrder.value = orderedIds;

    if (saving.value) {
        queuedOrder = orderedIds;

        return;
    }

    saving.value = true;

    const rollback = (message: string): void => {
        queuedOrder = null;
        optimisticOrder.value = null;
        toast.error(message, { testId: 'channels-reorder-error-toast' });
        router.reload({ only: CHANNEL_ORDER_PROPS });
    };

    router.put(
        reorderChannels.url(),
        { social_account_ids: orderedIds },
        {
            preserveScroll: true,
            preserveState: true,
            only: CHANNEL_ORDER_PROPS,
            onError: (errors) =>
                rollback(
                    errors.social_account_ids ??
                        Object.values(errors)[0] ??
                        trans('channels.reorder.failed'),
                ),
            onHttpException: () => {
                rollback(trans('channels.reorder.failed'));

                return false;
            },
            onNetworkError: () => {
                rollback(trans('channels.reorder.failed'));

                return false;
            },
            onFinish: () => {
                saving.value = false;

                const next = queuedOrder;
                queuedOrder = null;

                if (next) {
                    saveChannelOrder(next);
                } else {
                    optimisticOrder.value = null;
                }
            },
        },
    );
};

const focusHandle = (list: string, channelId: string): void => {
    nextTick(() =>
        document
            .querySelector<HTMLElement>(
                `[data-channel-handle="${list}:${channelId}"]`,
            )
            ?.focus(),
    );
};

/**
 * Drag-and-drop (by handle) and keyboard reordering for one rendered list of
 * channels. `list` keeps two lists on the same page from accepting each
 * other's drops; `order` returns the ids as currently rendered. The list
 * element bound with `vSortableChannelList` is the only drop target: the
 * dragged row collapses, `dragPreview` holds the slot it would land in, and
 * dropping anywhere over the list lands it there.
 */
export const useChannelOrder = (options: {
    list: string;
    order: () => string[];
}): {
    vSortableChannel: Directive<HTMLElement, string | null>;
    vSortableChannelList: Directive<HTMLElement, boolean>;
    onHandleKeydown: (event: KeyboardEvent, channelId: string) => void;
    dragPreview: Ref<ChannelDragPreview | null>;
    saving: Ref<boolean>;
} => {
    const { list } = options;
    const dragPreview = ref<ChannelDragPreview | null>(null);
    let listElement: HTMLElement | null = null;
    let startIndex = -1;
    let pointer: { clientX: number; clientY: number } | null = null;
    let stopEscape: (() => void) | null = null;

    const placeAt = (at: { clientX: number; clientY: number }): void => {
        if (listElement && dragPreview.value) {
            dragPreview.value = {
                ...dragPreview.value,
                index: placeholderIndexAt(listElement, at, {
                    attribute: 'sortableChannel',
                    draggedId: dragPreview.value.channelId,
                    axis: 'vertical',
                }),
            };
        }
    };

    const autoScroller = createDragAutoScroller('vertical', () => {
        if (pointer) {
            placeAt(pointer);
        }
    });

    const endPreview = (): void => {
        autoScroller.stop();
        pointer = null;
        startIndex = -1;
        dragPreview.value = null;
        stopEscape?.();
        stopEscape = null;
    };

    const startPreview = (channelId: string): void => {
        const row = listElement?.querySelector<HTMLElement>(
            `:scope > [data-sortable-channel="${channelId}"]`,
        );

        startIndex = options.order().indexOf(channelId);

        if (!row || startIndex === -1) {
            return;
        }

        dragPreview.value = {
            channelId,
            index: startIndex,
            height: row.offsetHeight,
        };
        stopEscape = cancelDragOnEscape(endPreview);
    };

    const dropPreview = (
        channelId: string,
        overList: boolean,
    ): void => {
        const preview = dragPreview.value;

        endPreview();

        if (!preview || preview.channelId !== channelId || !overList) {
            return;
        }

        const order = options.order();
        const finishIndex = preview.index;
        const from = order.indexOf(channelId);

        if (from === -1 || finishIndex === from) {
            return;
        }

        const next = order.filter((id) => id !== channelId);
        next.splice(finishIndex, 0, channelId);
        saveChannelOrder(next);
    };

    const stopMonitor = monitorForElements({
        canMonitor: ({ source }) => isChannelItem(source.data, list),
        onDragStart: ({ source }) => {
            if (isChannelItem(source.data, list)) {
                startPreview(source.data.channelId);
            }
        },
        onDrop: ({ source, location }) => {
            if (!isChannelItem(source.data, list)) {
                endPreview();

                return;
            }

            const overList = location.current.dropTargets.some((target) =>
                isListTarget(target.data, list),
            );

            if (overList) {
                placeAt(location.current.input);
            }

            dropPreview(source.data.channelId, overList);
        },
    });

    onBeforeUnmount(() => {
        stopMonitor();
        endPreview();
    });

    const register = (
        row: HTMLElement,
        handle: HTMLElement,
        channelId: string,
    ): (() => void) =>
        draggable({
            element: row,
            dragHandle: handle,
            getInitialData: () => ({ [ITEM_KEY]: list, channelId }),
            onGenerateDragPreview: ({ nativeSetDragImage }) =>
                disableNativeDragPreview({ nativeSetDragImage }),
            onDragStart: () => row.setAttribute('data-dragging', ''),
            onDrop: () => row.removeAttribute('data-dragging'),
        });

    const cleanups = new WeakMap<HTMLElement, () => void>();

    const unbind = (row: HTMLElement): void => {
        cleanups.get(row)?.();
        cleanups.delete(row);
        delete row.dataset.sortableChannel;
    };

    const bind = (row: HTMLElement, channelId: string | null): void => {
        const handle = channelId
            ? row.querySelector<HTMLElement>(
                  `[data-channel-handle="${list}:${channelId}"]`,
              )
            : null;

        if (channelId && handle) {
            row.dataset.sortableChannel = channelId;
            cleanups.set(row, register(row, handle, channelId));
        }
    };

    const vSortableChannel: Directive<HTMLElement, string | null> = {
        mounted: (row, { value }) => bind(row, value),
        updated: (row, { value, oldValue }) => {
            if (value === oldValue && cleanups.has(row) === (value !== null)) {
                return;
            }

            unbind(row);
            bind(row, value);
        },
        unmounted: (row) => unbind(row),
    };

    const registerList = (element: HTMLElement): (() => void) => {
        listElement = element;

        const stopTarget = dropTargetForElements({
            element,
            canDrop: ({ source }) => isChannelItem(source.data, list),
            getData: () => ({ [LIST_KEY]: list }),
            onDrag: ({ location }) => {
                if (!dragPreview.value) {
                    return;
                }

                pointer = location.current.input;
                placeAt(pointer);
                autoScroller.update(element, pointer);
            },
            onDragLeave: () => {
                autoScroller.stop();
                pointer = null;

                if (dragPreview.value) {
                    dragPreview.value = { ...dragPreview.value, index: startIndex };
                }
            },
        });

        return () => {
            stopTarget();

            if (listElement === element) {
                listElement = null;
            }
        };
    };

    const listCleanups = new WeakMap<HTMLElement, () => void>();

    const vSortableChannelList: Directive<HTMLElement, boolean> = {
        mounted: (element, { value }) => {
            if (value) {
                listCleanups.set(element, registerList(element));
            }
        },
        updated: (element, { value }) => {
            if (value === listCleanups.has(element)) {
                return;
            }

            listCleanups.get(element)?.();
            listCleanups.delete(element);

            if (value) {
                listCleanups.set(element, registerList(element));
            }
        },
        unmounted: (element) => {
            listCleanups.get(element)?.();
            listCleanups.delete(element);
        },
    };

    const move = (channelId: string, offset: -1 | 1): void => {
        const order = options.order();
        const from = order.indexOf(channelId);
        const finishIndex = from + offset;

        if (from === -1 || finishIndex < 0 || finishIndex >= order.length) {
            return;
        }

        saveChannelOrder(reorder({ list: order, startIndex: from, finishIndex }));
        focusHandle(list, channelId);
    };

    const onHandleKeydown = (event: KeyboardEvent, channelId: string): void => {
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
            return;
        }

        event.preventDefault();
        move(channelId, event.key === 'ArrowUp' ? -1 : 1);
    };

    return {
        vSortableChannel,
        vSortableChannelList,
        onHandleKeydown,
        dragPreview,
        saving,
    };
};
