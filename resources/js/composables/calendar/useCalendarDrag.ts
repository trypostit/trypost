import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    inject,
    onBeforeUnmount,
    ref,
    type Directive,
    type InjectionKey,
    type Ref,
} from 'vue';
import { toast } from 'vue-sonner';

import { update as updatePostSchedule } from '@/actions/App/Http/Controllers/App/PostScheduleController';
import dayjs from '@/dayjs';
import {
    canDragCalendarPost,
    dropInstant,
    needsCustomTimeConfirmation,
    type CalendarDropTarget,
} from '@/lib/calendarDrop';
import { PostStatus, ScheduleMode } from '@/types/post';
import type { CalendarPost, PostCard } from '@/types/publish';

export interface CalendarDrag {
    registerPost: (el: HTMLElement, post: PostCard) => () => void;
    registerTarget: (el: HTMLElement, target: CalendarDropTarget) => () => void;
    overTarget: Ref<string | null>;
    hoveredTarget: Ref<string | null>;
    draggingPostId: Ref<string | null>;
    movedPosts: Ref<Record<string, CalendarPost>>;
    vCalendarPost: Directive<HTMLElement, PostCard>;
    vCalendarTarget: Directive<HTMLElement, CalendarDropTarget>;
}

type CalendarDragContext = Pick<
    CalendarDrag,
    | 'overTarget'
    | 'hoveredTarget'
    | 'draggingPostId'
    | 'vCalendarPost'
    | 'vCalendarTarget'
>;

export const calendarDragKey: InjectionKey<CalendarDragContext> =
    Symbol('calendarDrag');

export interface CalendarDragLifecycle {
    onDragStart?: (post: PostCard) => void;
    onOver?: (
        post: PostCard,
        target: CalendarDropTarget | null,
        accepts: boolean,
    ) => void;
    onDrop?: (
        post: PostCard,
        target: CalendarDropTarget,
        scheduledAt: string | null,
    ) => void;
    onCancel?: (post: PostCard) => void;
    onMoved?: (post: PostCard, scheduledAt: string | null) => void;
}

interface Gesture {
    el: HTMLElement;
    post: PostCard;
    pointerId: number;
    pointerType: string;
    startX: number;
    startY: number;
    x: number;
    y: number;
    active: boolean;
    timer: number | null;
}

interface HoveredTarget {
    target: CalendarDropTarget;
    accepts: boolean;
}

const MOUSE_ACTIVATION_DISTANCE = 4;
const TOUCH_ACTIVATION_DELAY = 250;
const TOUCH_ACTIVATION_TOLERANCE = 5;
const AUTO_SCROLL_EDGE = 48;
const AUTO_SCROLL_MAX_STEP = 20;
const DRAGGABLE_ATTRIBUTE = 'data-calendar-draggable';
const DROP_ATTRIBUTE = 'data-calendar-drop';
const PREVIEW_CLASSES = [
    'pointer-events-none',
    'fixed',
    'z-50',
    'm-0',
    'opacity-80',
    'rotate-[2.765deg]',
];
const GRABBING_CLASS = 'cursor-grabbing';

export const calendarTargetKey = (target: CalendarDropTarget): string => {
    switch (target.kind) {
        case 'hour':
            return `hour-${target.at}`;
        case 'day':
            return `day-${target.dayKey}`;
        case 'slot':
            return `slot-${target.channelId}-${target.at}`;
        default:
            return 'undated';
    }
};

const isScrollable = (node: HTMLElement): boolean => {
    const overflow = getComputedStyle(node).overflowY;

    return (
        (overflow === 'auto' || overflow === 'scroll') &&
        node.scrollHeight > node.clientHeight
    );
};

const autoScrollStep = (distance: number): number =>
    Math.ceil(
        ((AUTO_SCROLL_EDGE - Math.max(distance, 0)) / AUTO_SCROLL_EDGE) *
            AUTO_SCROLL_MAX_STEP,
    );

const swallowClick = (event: Event): void => {
    event.preventDefault();
    event.stopPropagation();
};

const suppressNextClick = (): void => {
    window.addEventListener('click', swallowClick, {
        capture: true,
        once: true,
    });
    window.setTimeout(() => {
        window.removeEventListener('click', swallowClick, { capture: true });
    }, 0);
};

const noop = (): void => {};

export const useCalendarDragContext = (): CalendarDragContext =>
    inject(
        calendarDragKey,
        () => ({
            overTarget: ref<string | null>(null),
            hoveredTarget: ref<string | null>(null),
            draggingPostId: ref<string | null>(null),
            vCalendarPost: {},
            vCalendarTarget: {},
        }),
        true,
    );

export const useCalendarDrag = (
    options: {
        timezone: Readonly<Ref<string>>;
        now: Readonly<Ref<dayjs.Dayjs>>;
        canCreatePost: Readonly<Ref<boolean>>;
        reloadProps: string[];
        confirmCustomTime: (from: string, to: string) => Promise<boolean>;
    } & CalendarDragLifecycle,
): CalendarDrag => {
    const overTarget = ref<string | null>(null);
    const hoveredTarget = ref<string | null>(null);
    const draggingPostId = ref<string | null>(null);
    const movedPosts = ref<Record<string, CalendarPost>>({});

    const posts = new Map<Element, PostCard>();
    const targets = new Map<Element, CalendarDropTarget>();
    const postCleanups = new Map<HTMLElement, () => void>();
    const targetCleanups = new Map<HTMLElement, () => void>();

    let preview: HTMLElement | null = null;
    let previewOffset = { x: 0, y: 0 };
    let gesture: Gesture | null = null;
    let scrollFrame: number | null = null;

    const isDraggable = (post: PostCard): boolean =>
        canDragCalendarPost(post, options.now.value, options.canCreatePost.value);

    const instantFor = (
        post: PostCard,
        target: CalendarDropTarget,
    ): string | null | undefined =>
        dropInstant(post, target, options.timezone.value, options.now.value);

    const placePreview = (input: { clientX: number; clientY: number }): void => {
        if (preview) {
            preview.style.left = `${input.clientX - previewOffset.x}px`;
            preview.style.top = `${input.clientY - previewOffset.y}px`;
        }
    };

    const showPreview = (
        el: HTMLElement,
        input: { clientX: number; clientY: number },
    ): void => {
        const rect = el.getBoundingClientRect();
        const copy = el.cloneNode(true) as HTMLElement;

        copy.querySelectorAll('[data-testid], [id]').forEach((node) => {
            node.removeAttribute('data-testid');
            node.removeAttribute('id');
        });
        copy.removeAttribute('id');
        copy.removeAttribute(DRAGGABLE_ATTRIBUTE);
        copy.setAttribute('aria-hidden', 'true');
        copy.dataset.testid = 'calendar-drag-preview';
        copy.classList.add(...PREVIEW_CLASSES);
        copy.style.width = `${rect.width}px`;
        copy.style.height = `${rect.height}px`;

        previewOffset = {
            x: input.clientX - rect.left,
            y: input.clientY - rect.top,
        };
        preview = copy;
        placePreview(input);
        document.body.appendChild(copy);
    };

    const endDrag = (): void => {
        preview?.remove();
        preview = null;
        overTarget.value = null;
        hoveredTarget.value = null;
        draggingPostId.value = null;
        document.body.classList.remove(GRABBING_CLASS);
    };

    const movedPost = (
        post: PostCard,
        scheduledAt: string | null,
    ): CalendarPost => ({
        ...post,
        scheduled_at: scheduledAt,
        calendar_at: scheduledAt ?? '',
        schedule_mode:
            post.status === PostStatus.Scheduled
                ? ScheduleMode.Custom
                : post.schedule_mode,
    });

    const forget = (postId: string): void => {
        movedPosts.value = Object.fromEntries(
            Object.entries(movedPosts.value).filter(([id]) => id !== postId),
        );
    };

    const reloadedPost = (page: Page, postId: string): CalendarPost | undefined =>
        Object.values(
            (page.props.posts as Record<string, CalendarPost[]> | undefined) ??
                {},
        )
            .flat()
            .find((reloaded) => reloaded.id === postId);

    const rollback = (postId: string, message?: string): void => {
        forget(postId);
        toast.error(message ?? trans('calendar.reschedule.error'), {
            testId: 'calendar-reschedule-error-toast',
        });
    };

    const move = async (
        post: PostCard,
        target: CalendarDropTarget,
        scheduledAt: string | null,
    ): Promise<void> => {
        if (
            needsCustomTimeConfirmation(post, target) &&
            post.scheduled_at &&
            scheduledAt &&
            !(await options.confirmCustomTime(post.scheduled_at, scheduledAt))
        ) {
            options.onCancel?.(post);

            return;
        }

        options.onDrop?.(post, target, scheduledAt);

        movedPosts.value = {
            ...movedPosts.value,
            [post.id]: movedPost(post, scheduledAt),
        };

        let failed = false;

        router.put(
            updatePostSchedule.url(post.id),
            {
                action: 'reschedule',
                scheduled_at: scheduledAt,
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: [...options.reloadProps, 'flash'],
                reset: ['undatedDrafts'],
                onSuccess: (page) => {
                    if (
                        reloadedPost(page, post.id)?.status ===
                        PostStatus.PendingApproval
                    ) {
                        toast.success(trans('posts.approvals.requested'), {
                            testId: 'calendar-reschedule-approval-toast',
                        });
                    }
                },
                onError: () => {
                    failed = true;
                    rollback(post.id);
                },
                onHttpException: () => {
                    failed = true;
                    rollback(post.id);

                    return false;
                },
                onNetworkError: () => {
                    failed = true;
                    rollback(post.id);

                    return false;
                },
                onCancel: () => {
                    failed = true;
                },
                onFinish: () => {
                    forget(post.id);

                    if (!failed) {
                        options.onMoved?.(post, scheduledAt);
                    }
                },
            },
        );
    };

    const targetAt = (
        post: PostCard,
        x: number,
        y: number,
    ): HoveredTarget | null => {
        let refusing: CalendarDropTarget | null = null;

        for (const element of document.elementsFromPoint(x, y)) {
            const target = targets.get(element);

            if (!target) {
                continue;
            }

            if (instantFor(post, target) !== undefined) {
                return { target, accepts: true };
            }

            refusing ??= target;
        }

        return refusing ? { target: refusing, accepts: false } : null;
    };

    const updateOver = (): void => {
        if (!gesture?.active) {
            return;
        }

        placePreview({ clientX: gesture.x, clientY: gesture.y });

        const hovered = targetAt(gesture.post, gesture.x, gesture.y);
        const key = hovered ? calendarTargetKey(hovered.target) : null;
        const acceptingKey = hovered?.accepts ? key : null;

        if (key === hoveredTarget.value && acceptingKey === overTarget.value) {
            return;
        }

        hoveredTarget.value = key;
        overTarget.value = acceptingKey;
        options.onOver?.(
            gesture.post,
            hovered?.target ?? null,
            hovered?.accepts ?? false,
        );
    };

    const edgeScroller = (
        x: number,
        y: number,
    ): { scroller: HTMLElement; step: number } | null => {
        let node: Element | null = document.elementsFromPoint(x, y)[0] ?? null;

        while (node && node !== document.body) {
            if (node instanceof HTMLElement && isScrollable(node)) {
                const rect = node.getBoundingClientRect();
                const fromTop = y - rect.top;
                const fromBottom = rect.bottom - y;

                if (fromBottom < AUTO_SCROLL_EDGE) {
                    return { scroller: node, step: autoScrollStep(fromBottom) };
                }

                if (fromTop < AUTO_SCROLL_EDGE) {
                    return { scroller: node, step: -autoScrollStep(fromTop) };
                }
            }

            node = node.parentElement;
        }

        return null;
    };

    const autoScroll = (): void => {
        if (!gesture?.active) {
            scrollFrame = null;

            return;
        }

        const edge = edgeScroller(gesture.x, gesture.y);

        if (edge) {
            const before = edge.scroller.scrollTop;

            edge.scroller.scrollTop += edge.step;

            if (edge.scroller.scrollTop !== before) {
                updateOver();
            }
        }

        scrollFrame = requestAnimationFrame(autoScroll);
    };

    const preventWhileDragging = (event: Event): void => {
        if (gesture?.active) {
            event.preventDefault();
        }
    };

    const onPointerMove = (event: PointerEvent): void => {
        if (!gesture || event.pointerId !== gesture.pointerId) {
            return;
        }

        if (gesture.pointerType !== 'touch' && event.buttons === 0) {
            cancel();

            return;
        }

        gesture.x = event.clientX;
        gesture.y = event.clientY;

        if (gesture.active) {
            event.preventDefault();
            updateOver();

            return;
        }

        const distance = Math.hypot(
            gesture.x - gesture.startX,
            gesture.y - gesture.startY,
        );

        if (gesture.pointerType === 'touch') {
            if (distance > TOUCH_ACTIVATION_TOLERANCE) {
                release();
            }

            return;
        }

        if (distance > MOUSE_ACTIVATION_DISTANCE) {
            activate();
        }
    };

    const onPointerUp = (event: PointerEvent): void => {
        if (!gesture || event.pointerId !== gesture.pointerId) {
            return;
        }

        if (!gesture.active) {
            release();

            return;
        }

        gesture.x = event.clientX;
        gesture.y = event.clientY;

        const post = gesture.post;
        const hovered = targetAt(post, gesture.x, gesture.y);

        release();
        suppressNextClick();

        const scheduledAt = hovered?.accepts
            ? instantFor(post, hovered.target)
            : undefined;

        if (!hovered || scheduledAt === undefined) {
            options.onCancel?.(post);

            return;
        }

        void move(post, hovered.target, scheduledAt);
    };

    const cancel = (): void => {
        const post = gesture?.active ? gesture.post : null;

        release();

        if (post) {
            options.onCancel?.(post);
        }
    };

    const onPointerCancel = (event: PointerEvent): void => {
        if (gesture && event.pointerId === gesture.pointerId) {
            cancel();
        }
    };

    const onVisibilityChange = (): void => {
        if (document.visibilityState === 'hidden') {
            cancel();
        }
    };

    const onKeyDown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape' && gesture) {
            if (gesture.active) {
                event.preventDefault();
                event.stopPropagation();
            }

            cancel();
        }
    };

    const listen = (): void => {
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerCancel);
        window.addEventListener('keydown', onKeyDown, { capture: true });
        window.addEventListener('contextmenu', preventWhileDragging);
        window.addEventListener('blur', cancel);
        document.addEventListener('visibilitychange', onVisibilityChange);
    };

    const unlisten = (): void => {
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', onPointerCancel);
        window.removeEventListener('keydown', onKeyDown, { capture: true });
        window.removeEventListener('contextmenu', preventWhileDragging);
        window.removeEventListener('blur', cancel);
        document.removeEventListener('visibilitychange', onVisibilityChange);
    };

    const activate = (): void => {
        if (!gesture || gesture.active) {
            return;
        }

        if (gesture.timer !== null) {
            window.clearTimeout(gesture.timer);
            gesture.timer = null;
        }

        gesture.active = true;
        gesture.el.style.touchAction = 'none';
        draggingPostId.value = gesture.post.id;
        document.body.classList.add(GRABBING_CLASS);
        window.getSelection()?.removeAllRanges();
        showPreview(gesture.el, {
            clientX: gesture.startX,
            clientY: gesture.startY,
        });
        options.onDragStart?.(gesture.post);
        updateOver();
        scrollFrame = requestAnimationFrame(autoScroll);
    };

    const release = (): void => {
        if (gesture?.timer !== null && gesture?.timer !== undefined) {
            window.clearTimeout(gesture.timer);
        }

        if (gesture?.active) {
            gesture.el.style.removeProperty('touch-action');
        }

        if (scrollFrame !== null) {
            cancelAnimationFrame(scrollFrame);
            scrollFrame = null;
        }

        gesture = null;
        unlisten();
        endDrag();
    };

    const startGesture = (el: HTMLElement, event: PointerEvent): void => {
        const post = posts.get(el);

        if (
            gesture ||
            !post ||
            !isDraggable(post) ||
            !event.isPrimary ||
            (event.pointerType !== 'touch' && event.button !== 0)
        ) {
            return;
        }

        gesture = {
            el,
            post,
            pointerId: event.pointerId,
            pointerType: event.pointerType,
            startX: event.clientX,
            startY: event.clientY,
            x: event.clientX,
            y: event.clientY,
            active: false,
            timer:
                event.pointerType === 'touch'
                    ? window.setTimeout(activate, TOUCH_ACTIVATION_DELAY)
                    : null,
        };
        listen();
    };

    onBeforeUnmount(cancel);

    const registerPost = (el: HTMLElement, post: PostCard): (() => void) => {
        posts.set(el, post);

        const onPointerDown = (event: PointerEvent): void =>
            startGesture(el, event);
        const onDragStart = (event: DragEvent): void => event.preventDefault();

        el.setAttribute(DRAGGABLE_ATTRIBUTE, 'true');
        el.style.setProperty('-webkit-touch-callout', 'none');
        el.style.setProperty('user-select', 'none');
        el.style.setProperty('-webkit-user-select', 'none');
        el.addEventListener('pointerdown', onPointerDown);
        el.addEventListener('dragstart', onDragStart);
        el.addEventListener('touchmove', preventWhileDragging, {
            passive: false,
        });

        return () => {
            if (gesture?.el === el) {
                cancel();
            }

            posts.delete(el);
            el.removeAttribute(DRAGGABLE_ATTRIBUTE);
            el.style.removeProperty('-webkit-touch-callout');
            el.style.removeProperty('user-select');
            el.style.removeProperty('-webkit-user-select');
            el.removeEventListener('pointerdown', onPointerDown);
            el.removeEventListener('dragstart', onDragStart);
            el.removeEventListener('touchmove', preventWhileDragging);
        };
    };

    const registerTarget = (
        el: HTMLElement,
        target: CalendarDropTarget,
    ): (() => void) => {
        targets.set(el, target);
        el.setAttribute(DROP_ATTRIBUTE, calendarTargetKey(target));

        return () => {
            targets.delete(el);
            el.removeAttribute(DROP_ATTRIBUTE);
        };
    };

    const syncPost = (el: HTMLElement, post: PostCard): void => {
        posts.set(el, post);

        const registered = postCleanups.get(el);

        if (isDraggable(post) && !registered) {
            postCleanups.set(el, registerPost(el, post));
        } else if (!isDraggable(post) && registered) {
            registered();
            postCleanups.delete(el);
        }
    };

    const unregisterPost = (el: HTMLElement): void => {
        (postCleanups.get(el) ?? noop)();
        postCleanups.delete(el);
        posts.delete(el);
    };

    const vCalendarPost: Directive<HTMLElement, PostCard> = {
        mounted: (el, { value }) => syncPost(el, value),
        updated: (el, { value }) => syncPost(el, value),
        beforeUnmount: unregisterPost,
    };

    const vCalendarTarget: Directive<HTMLElement, CalendarDropTarget> = {
        mounted: (el, { value }) => {
            targetCleanups.set(el, registerTarget(el, value));
        },
        updated: (el, { value }) => {
            targets.set(el, value);
            el.setAttribute(DROP_ATTRIBUTE, calendarTargetKey(value));
        },
        beforeUnmount: (el) => {
            (targetCleanups.get(el) ?? noop)();
            targetCleanups.delete(el);
        },
    };

    return {
        registerPost,
        registerTarget,
        overTarget,
        hoveredTarget,
        draggingPostId,
        movedPosts,
        vCalendarPost,
        vCalendarTarget,
    };
};
