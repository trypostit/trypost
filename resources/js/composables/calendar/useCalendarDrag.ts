import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    inject,
    onBeforeUnmount,
    ref,
    type ComputedRef,
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
    entersQueueSlot,
    needsCustomTimeConfirmation,
    type CalendarDropTarget,
} from '@/lib/calendarDrop';
import { cancelDragOnEscape, edgeScrollDelta } from '@/lib/dragPlaceholder';
import { PostStatus, ScheduleMode } from '@/types/post';
import type {
    CalendarPost,
    PostCard,
    ScrollUndatedDrafts,
    UndatedDraft,
} from '@/types/publish';

const CALENDAR_AUTO_SCROLL_STEP = 20;

export interface CalendarDrag {
    overTarget: Ref<string | null>;
    hoveredTarget: Ref<string | null>;
    draggingPostId: Ref<string | null>;
    movedPosts: Ref<Record<string, CalendarPost>>;
    vCalendarPost: Directive<HTMLElement, PostCard>;
    vCalendarTarget: Directive<HTMLElement, CalendarDropTarget | null>;
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
    onCancel?: (post: PostCard) => void;
    onConfirmed?: (
        post: PostCard,
        target: CalendarDropTarget,
        scheduledAt: string | null,
    ) => void;
    onRejected?: (post: PostCard) => void;
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
const DRAGGABLE_ATTRIBUTE = 'data-calendar-draggable';
const DROP_ATTRIBUTE = 'data-calendar-drop';
const SCROLLER_ATTRIBUTE = 'data-calendar-scroller';
const PREVIEW_CLASSES = [
    'pointer-events-none',
    'fixed',
    'z-50',
    'm-0',
    'opacity-80',
    'rotate-[2.765deg]',
];
export const HINT_ID_PREFIX = 'calendar-drag-hint-';
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
        canCreatePost: Readonly<Ref<boolean>>;
        requiresApproval: Readonly<Ref<boolean>>;
        reloadProps: () => string[];
        confirmCustomTime: (from: string, to: string) => Promise<boolean>;
        describeInstant: (
            at: string,
            target: CalendarDropTarget,
            previousAt: string | null,
        ) => string;
    } & CalendarDragLifecycle,
): CalendarDrag => {
    const page = usePage();
    const overTarget = ref<string | null>(null);
    const hoveredTarget = ref<string | null>(null);
    const draggingPostId = ref<string | null>(null);
    const movedPosts = ref<Record<string, CalendarPost>>({});

    const posts = new Map<Element, PostCard>();
    const targets = new Map<Element, CalendarDropTarget>();
    const postCleanups = new Map<HTMLElement, () => void>();

    let preview: HTMLElement | null = null;
    let previewOffset = { x: 0, y: 0 };
    let gesture: Gesture | null = null;
    let frame: number | null = null;
    let scroller: HTMLElement | null = null;
    let pointerMoved = false;
    let stopEscape: (() => void) | null = null;
    let movesSent = 0;
    let newestMoveShown = 0;

    const isDraggable = (post: PostCard): boolean =>
        !(post.id in movedPosts.value) &&
        canDragCalendarPost(post, dayjs(), options.canCreatePost.value);

    const instantFor = (
        post: PostCard,
        target: CalendarDropTarget,
    ): string | null | undefined =>
        dropInstant(post, target, options.timezone.value, dayjs());

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
        copy.removeAttribute('aria-describedby');
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

    const movedScheduleMode = (
        post: PostCard,
        target: CalendarDropTarget,
    ): PostCard['schedule_mode'] => {
        if (post.status !== PostStatus.Scheduled) {
            return post.schedule_mode;
        }

        return entersQueueSlot(post, target)
            ? ScheduleMode.Queue
            : ScheduleMode.Custom;
    };

    const movedPost = (
        post: PostCard,
        target: CalendarDropTarget,
        scheduledAt: string | null,
    ): CalendarPost => ({
        ...post,
        status: scheduledAt === null ? PostStatus.Draft : post.status,
        scheduled_at: scheduledAt,
        calendar_at: scheduledAt ?? '',
        schedule_mode: movedScheduleMode(post, target),
    });

    const forget = (postId: string): void => {
        const rest = { ...movedPosts.value };

        delete rest[postId];
        movedPosts.value = rest;
    };

    const rollback = (postId: string, message?: string): void => {
        forget(postId);
        toast.error(message ?? trans('calendar.reschedule.error'), {
            testId: 'calendar-reschedule-error-toast',
        });
    };

    const successMessage = (
        post: PostCard,
        target: CalendarDropTarget,
        scheduledAt: string | null,
    ): string => {
        const leavesTheQueue =
            post.status === PostStatus.Scheduled &&
            post.schedule_mode === ScheduleMode.Queue &&
            !entersQueueSlot(post, target);
        const queueNote = leavesTheQueue
            ? ` ${trans('calendar.reschedule.toast.unqueued_suffix')}`
            : '';

        if (scheduledAt === null) {
            return post.status === PostStatus.Draft
                ? trans('calendar.reschedule.toast.undated')
                : `${trans('calendar.reschedule.toast.unscheduled')}${queueNote}`;
        }

        const time = options.describeInstant(
            scheduledAt,
            target,
            post.scheduled_at,
        );
        const message = trans(
            post.status === PostStatus.Draft
                ? 'calendar.reschedule.toast.moved_draft'
                : 'calendar.reschedule.toast.moved',
            { time },
        );

        return `${message}${queueNote}`;
    };

    const postIn = (
        props: Record<string, unknown>,
        postId: string,
    ): CalendarPost | null =>
        Object.values(
            (props.posts as Record<string, CalendarPost[]> | undefined) ?? {},
        )
            .flat()
            .find((candidate) => candidate.id === postId) ?? null;

    const isSameInstant = (
        stored: string | null,
        requested: string | null,
    ): boolean =>
        stored === null || requested === null
            ? stored === requested
            : dayjs.utc(stored).isSame(dayjs.utc(requested));

    /**
     * The custom time dialog can stay open for a while: once confirmed, the drop
     * is checked again against the clock, and its time worked out again.
     */
    const confirmedInstant = async (
        post: PostCard,
        target: CalendarDropTarget,
        droppedAt: string | null,
    ): Promise<string | null | undefined> => {
        if (
            !needsCustomTimeConfirmation(post, target) ||
            !post.scheduled_at ||
            !droppedAt
        ) {
            return droppedAt;
        }

        if (!(await options.confirmCustomTime(post.scheduled_at, droppedAt))) {
            return undefined;
        }

        return isDraggable(post) ? instantFor(post, target) : undefined;
    };

    const move = async (
        post: PostCard,
        target: CalendarDropTarget,
        droppedAt: string | null,
    ): Promise<void> => {
        const scheduledAt = await confirmedInstant(post, target, droppedAt);

        if (scheduledAt === undefined) {
            options.onCancel?.(post);

            return;
        }

        movedPosts.value = {
            ...movedPosts.value,
            [post.id]: movedPost(post, target, scheduledAt),
        };

        let settled = false;

        const confirm = (): void => {
            if (!settled) {
                settled = true;
                options.onConfirmed?.(post, target, scheduledAt);
            }
        };

        const reject = (): void => {
            if (!settled) {
                settled = true;
                options.onRejected?.(post);
            }
        };

        const fail = (message?: string): false => {
            reject();
            rollback(post.id, message);

            return false;
        };

        const moveId = ++movesSent;
        const component = page.component;
        const touchesNoDate =
            target.kind === 'undated' || post.scheduled_at === null;
        const props = (): string[] =>
            options
                .reloadProps()
                .filter((prop) => touchesNoDate || prop !== 'undatedDrafts');
        const reset = touchesNoDate ? ['undatedDrafts'] : [];
        let reloaded: CalendarPost | null | undefined;
        let staleResponse = false;

        const announceApproval = (): void => {
            toast.success(trans('posts.approvals.requested'), {
                testId: 'calendar-reschedule-approval-toast',
            });
            confirm();
        };

        const announceMoved = (): void => {
            confirm();
            toast.success(successMessage(post, target, scheduledAt), {
                testId: 'calendar-reschedule-success-toast',
            });
        };

        /**
         * Settles a move from a reload of the page now shown, when the move's
         * own answer could not be read: the post at the asked time was moved,
         * at another time it was not, and outside this page nothing is known.
         */
        const settleFromReload = (shown: CalendarPost | null): void => {
            if (settled || shown === null) {
                return;
            }

            if (!isSameInstant(shown.scheduled_at, scheduledAt)) {
                reject();

                return;
            }

            if (shown.status === PostStatus.PendingApproval) {
                announceApproval();

                return;
            }

            announceMoved();
        };

        /**
         * Reloads the page shown instead of reading a move's answer. Inertia
         * reloads asynchronously, so an answer that lands after the user moved
         * to another page of the same path (a filter, a period) is dropped like
         * a stale move answer instead of bringing the old page back.
         */
        const reloadInstead = (settleFromIt: boolean): void => {
            staleResponse = true;

            let shown: CalendarPost | null | undefined;

            router.reload({
                only: props(),
                reset,
                onBeforeUpdate: (response) => {
                    if (response.url !== page.url) {
                        response.props = { ...page.props };
                        response.url = page.url;

                        return;
                    }

                    shown = postIn(response.props, post.id);
                },
                onSuccess: () => {
                    if (settleFromIt && shown !== undefined) {
                        settleFromReload(shown);
                    }
                },
                onFinish: () => forget(post.id),
            });
        };

        router.put(
            updatePostSchedule.url(post.id),
            {
                action: 'reschedule',
                scheduled_at: scheduledAt,
                queue_slot: entersQueueSlot(post, target),
            },
            {
                async: true,
                preserveScroll: true,
                preserveState: true,
                only: [...props(), 'flash'],
                reset,
                onBeforeUpdate: (response) => {
                    reloaded = postIn(response.props, post.id);

                    if (response.url === page.url && moveId >= newestMoveShown) {
                        newestMoveShown = moveId;

                        return;
                    }

                    staleResponse = true;
                    response.props = {
                        ...page.props,
                        flash: response.props.flash,
                        errors: response.props.errors,
                    };
                    response.url = page.url;
                },
                onSuccess: () => {
                    if (reloaded === undefined) {
                        if (page.component === component) {
                            reloadInstead(true);
                        }

                        return;
                    }

                    if (staleResponse) {
                        reloadInstead(false);
                    }

                    if (settled) {
                        return;
                    }

                    const leftTheList =
                        reloaded === null &&
                        scheduledAt !== null &&
                        post.status !== PostStatus.Draft;

                    if (leftTheList && !options.requiresApproval.value) {
                        reject();

                        return;
                    }

                    if (
                        leftTheList ||
                        reloaded?.status === PostStatus.PendingApproval
                    ) {
                        announceApproval();

                        return;
                    }

                    if (
                        reloaded &&
                        !isSameInstant(reloaded.scheduled_at, scheduledAt)
                    ) {
                        reject();

                        return;
                    }

                    announceMoved();
                },
                onError: (errors) => {
                    fail(Object.values(errors)[0]);
                },
                onHttpException: () => fail(),
                onNetworkError: () => fail(),
                onCancel: reject,
                onFinish: () => {
                    if (!staleResponse) {
                        forget(post.id);
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

    const tick = (): void => {
        if (!gesture?.active) {
            frame = null;

            return;
        }

        if (scroller) {
            const before = scroller.scrollTop;

            scroller.scrollTop += edgeScrollDelta(
                scroller,
                { clientX: gesture.x, clientY: gesture.y },
                'vertical',
                CALENDAR_AUTO_SCROLL_STEP,
            );
            pointerMoved ||= scroller.scrollTop !== before;
        }

        if (pointerMoved) {
            pointerMoved = false;
            updateOver();
        }

        frame = requestAnimationFrame(tick);
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
            pointerMoved = true;

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

        if (!hovered || scheduledAt === undefined || !isDraggable(post)) {
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

    const listen = (): void => {
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerCancel);
        stopEscape = cancelDragOnEscape(cancel, () => gesture?.active === true);
        window.addEventListener('contextmenu', preventWhileDragging);
        window.addEventListener('blur', cancel);
        document.addEventListener('visibilitychange', onVisibilityChange);
    };

    const unlisten = (): void => {
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
        window.removeEventListener('pointercancel', onPointerCancel);
        stopEscape?.();
        stopEscape = null;
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
        scroller = document.querySelector<HTMLElement>(`[${SCROLLER_ATTRIBUTE}]`);
        options.onDragStart?.(gesture.post);
        updateOver();
        frame = requestAnimationFrame(tick);
    };

    const release = (): void => {
        if (gesture?.timer !== null && gesture?.timer !== undefined) {
            window.clearTimeout(gesture.timer);
        }

        if (gesture?.active) {
            gesture.el.style.removeProperty('touch-action');
        }

        if (frame !== null) {
            cancelAnimationFrame(frame);
            frame = null;
        }

        gesture = null;
        scroller = null;
        pointerMoved = false;
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
        const onPointerDown = (event: PointerEvent): void =>
            startGesture(el, event);
        const onDragStart = (event: DragEvent): void => event.preventDefault();

        el.setAttribute(DRAGGABLE_ATTRIBUTE, 'true');
        el.setAttribute('aria-describedby', `${HINT_ID_PREFIX}${post.id}`);
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

            el.removeAttribute(DRAGGABLE_ATTRIBUTE);
            el.removeAttribute('aria-describedby');
            el.style.removeProperty('-webkit-touch-callout');
            el.style.removeProperty('user-select');
            el.style.removeProperty('-webkit-user-select');
            el.removeEventListener('pointerdown', onPointerDown);
            el.removeEventListener('dragstart', onDragStart);
            el.removeEventListener('touchmove', preventWhileDragging);
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

    const forgetTarget = (el: HTMLElement): void => {
        targets.delete(el);
        el.removeAttribute(DROP_ATTRIBUTE);
    };

    const syncTarget = (
        el: HTMLElement,
        target: CalendarDropTarget | null,
    ): void => {
        if (!target) {
            forgetTarget(el);

            return;
        }

        targets.set(el, target);
        el.setAttribute(DROP_ATTRIBUTE, calendarTargetKey(target));
    };

    const vCalendarPost: Directive<HTMLElement, PostCard> = {
        mounted: (el, { value }) => syncPost(el, value),
        updated: (el, { value }) => syncPost(el, value),
        beforeUnmount: (el) => {
            postCleanups.get(el)?.();
            postCleanups.delete(el);
            posts.delete(el);
        },
    };

    const vCalendarTarget: Directive<HTMLElement, CalendarDropTarget | null> = {
        mounted: (el, { value }) => syncTarget(el, value),
        updated: (el, { value }) => syncTarget(el, value),
        beforeUnmount: forgetTarget,
    };

    return {
        overTarget,
        hoveredTarget,
        draggingPostId,
        movedPosts,
        vCalendarPost,
        vCalendarTarget,
    };
};

export const useCalendarOverlay = (options: {
    movedPosts: Readonly<Ref<Record<string, CalendarPost>>>;
    posts: () => Record<string, CalendarPost[]>;
    undatedDrafts: () => ScrollUndatedDrafts | undefined;
    timezone: Readonly<Ref<string>>;
}): {
    displayedPosts: ComputedRef<Record<string, CalendarPost[]>>;
    displayedUndatedDrafts: ComputedRef<UndatedDraft[] | null>;
} => {
    const displayedPosts = computed<Record<string, CalendarPost[]>>(() => {
        const moved = options.movedPosts.value;
        const posts = options.posts();

        if (Object.keys(moved).length === 0) {
            return posts;
        }

        const grouped: Record<string, CalendarPost[]> = {};
        const place = (post: CalendarPost): void => {
            const key = dayjs
                .utc(post.calendar_at)
                .tz(options.timezone.value)
                .format('YYYY-MM-DD');

            grouped[key] = [...(grouped[key] ?? []), post];
        };

        Object.values(posts)
            .flat()
            .filter((post) => !moved[post.id])
            .forEach(place);
        Object.values(moved)
            .filter((post) => post.scheduled_at !== null)
            .forEach(place);

        return grouped;
    });

    const displayedUndatedDrafts = computed<UndatedDraft[] | null>(() => {
        const undatedDrafts = options.undatedDrafts();

        if (!undatedDrafts) {
            return null;
        }

        const moved = options.movedPosts.value;

        return [
            ...Object.values(moved)
                .filter((post) => post.scheduled_at === null)
                .map((post): UndatedDraft => ({ ...post, scheduled_at: null })),
            ...undatedDrafts.data.filter((draft) => !moved[draft.id]),
        ];
    });

    return { displayedPosts, displayedUndatedDrafts };
};
