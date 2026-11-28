export type DragAxis = 'vertical' | 'horizontal';

const AUTO_SCROLL_EDGE = 48;
const AUTO_SCROLL_MAX_STEP = 14;

/**
 * The slot a dragged item would land in among the children of `container`
 * that carry `data-<attribute>`, ignoring the dragged one. Positions come
 * from the layout (`offsetTop` / `offsetLeft`), not from rects, so the FLIP
 * transforms of a reflowing list never move the target under the pointer.
 * The container must be the children's offset parent (`position: relative`).
 */
export const placeholderIndexAt = (
    container: HTMLElement,
    pointer: { clientX: number; clientY: number },
    options: { attribute: string; draggedId: string; axis: DragAxis },
): number => {
    const rect = container.getBoundingClientRect();
    const vertical = options.axis === 'vertical';
    const rtl = !vertical && getComputedStyle(container).direction === 'rtl';
    const position = vertical
        ? pointer.clientY - rect.top + container.scrollTop
        : pointer.clientX - rect.left + container.scrollLeft;

    return [...container.children].filter((child): child is HTMLElement => {
        if (
            !(child instanceof HTMLElement) ||
            child.dataset[options.attribute] === undefined ||
            child.dataset[options.attribute] === options.draggedId
        ) {
            return false;
        }

        const middle = vertical
            ? child.offsetTop + child.offsetHeight / 2
            : child.offsetLeft + child.offsetWidth / 2;

        return rtl ? middle > position : middle < position;
    }).length;
};

/**
 * How far to scroll `container` this frame while the pointer rests within
 * 48px of one of its edges: negative toward the start, 0 away from the edges.
 */
export const edgeScrollDelta = (
    container: HTMLElement,
    pointer: { clientX: number; clientY: number },
    axis: DragAxis,
    maxStep: number = AUTO_SCROLL_MAX_STEP,
): number => {
    const rect = container.getBoundingClientRect();
    const vertical = axis === 'vertical';
    const coordinate = vertical ? pointer.clientY : pointer.clientX;
    const fromStart = coordinate - (vertical ? rect.top : rect.left);
    const fromEnd = (vertical ? rect.bottom : rect.right) - coordinate;
    const speed = (distance: number): number =>
        Math.ceil(maxStep * (1 - Math.max(distance, 0) / AUTO_SCROLL_EDGE));

    if (fromStart < AUTO_SCROLL_EDGE) {
        return -speed(fromStart);
    }

    if (fromEnd < AUTO_SCROLL_EDGE) {
        return speed(fromEnd);
    }

    return 0;
};

/**
 * Scrolls a container while the pointer rests near one of its edges during a
 * drag, calling `onScroll` after every step so the placeholder can follow.
 */
export const createDragAutoScroller = (
    axis: DragAxis,
    onScroll: () => void,
): {
    update: (
        container: HTMLElement | null,
        pointer: { clientX: number; clientY: number } | null,
    ) => void;
    stop: () => void;
} => {
    let container: HTMLElement | null = null;
    let pointer: { clientX: number; clientY: number } | null = null;
    let frame = 0;

    const step = (): number =>
        container && pointer
            ? edgeScrollDelta(container, pointer, axis)
            : 0;

    const tick = (): void => {
        frame = 0;

        const delta = step();

        if (!container || delta === 0) {
            return;
        }

        const property = axis === 'vertical' ? 'scrollTop' : 'scrollLeft';
        const before = container[property];
        container[property] += delta;

        if (container[property] !== before) {
            onScroll();
        }

        frame = requestAnimationFrame(tick);
    };

    const stop = (): void => {
        cancelAnimationFrame(frame);
        frame = 0;
        container = null;
        pointer = null;
    };

    const update = (
        nextContainer: HTMLElement | null,
        nextPointer: { clientX: number; clientY: number } | null,
    ): void => {
        if (!nextContainer || !nextPointer) {
            stop();

            return;
        }

        container = nextContainer;
        pointer = nextPointer;

        if (!frame && step() !== 0) {
            frame = requestAnimationFrame(tick);
        }
    };

    return { update, stop };
};

/**
 * Calls `onCancel` when Escape is pressed during a drag; returns the cleanup.
 * When `swallow` answers true, the key stops there, so an open dialog under
 * the drag does not close as well.
 */
export const cancelDragOnEscape = (
    onCancel: () => void,
    swallow: () => boolean = () => false,
): (() => void) => {
    const onKeydown = (event: KeyboardEvent): void => {
        if (event.key !== 'Escape') {
            return;
        }

        if (swallow()) {
            event.preventDefault();
            event.stopPropagation();
        }

        onCancel();
    };

    window.addEventListener('keydown', onKeydown, true);

    return () => window.removeEventListener('keydown', onKeydown, true);
};
