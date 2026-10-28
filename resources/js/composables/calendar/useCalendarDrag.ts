import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { disableNativeDragPreview } from '@atlaskit/pragmatic-drag-and-drop/element/disable-native-drag-preview';
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
    draggingPostId: Ref<string | null>;
    movedPosts: Ref<Record<string, CalendarPost>>;
    vCalendarPost: Directive<HTMLElement, PostCard>;
    vCalendarTarget: Directive<HTMLElement, CalendarDropTarget>;
}

type CalendarDragContext = Pick<
    CalendarDrag,
    'overTarget' | 'draggingPostId' | 'vCalendarPost' | 'vCalendarTarget'
>;

export const calendarDragKey: InjectionKey<CalendarDragContext> =
    Symbol('calendarDrag');

const POST_KEY = 'calendarPost';
const TARGET_KEY = 'calendarTarget';
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

const isCalendarPost = (data: Record<string | symbol, unknown>): boolean =>
    data[POST_KEY] === true;

const targetKeyOf = (
    data: Record<string | symbol, unknown> | undefined,
): string | null =>
    data?.[TARGET_KEY] === true && typeof data.key === 'string'
        ? data.key
        : null;

const noop = (): void => {};

export const useCalendarDragContext = (): CalendarDragContext =>
    inject(
        calendarDragKey,
        () => ({
            overTarget: ref<string | null>(null),
            draggingPostId: ref<string | null>(null),
            vCalendarPost: {},
            vCalendarTarget: {},
        }),
        true,
    );

export const useCalendarDrag = (options: {
    timezone: Readonly<Ref<string>>;
    now: Readonly<Ref<dayjs.Dayjs>>;
    canCreatePost: Readonly<Ref<boolean>>;
    reloadProps: string[];
    confirmCustomTime: (from: string, to: string) => Promise<boolean>;
    onMoved?: () => void;
}): CalendarDrag => {
    const overTarget = ref<string | null>(null);
    const draggingPostId = ref<string | null>(null);
    const movedPosts = ref<Record<string, CalendarPost>>({});

    const posts = new Map<Element, PostCard>();
    const targets = new Map<Element, CalendarDropTarget>();
    const postCleanups = new Map<HTMLElement, () => void>();
    const targetCleanups = new Map<HTMLElement, () => void>();

    let preview: HTMLElement | null = null;
    let previewOffset = { x: 0, y: 0 };

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
        copy.removeAttribute('draggable');
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
        draggingPostId.value = null;
        document.body.classList.remove(GRABBING_CLASS);
    };

    const movedPost = (
        post: PostCard,
        target: CalendarDropTarget,
        scheduledAt: string | null,
    ): CalendarPost => {
        const staysQueued =
            post.schedule_mode === ScheduleMode.Queue &&
            !needsCustomTimeConfirmation(post, target);

        return {
            ...post,
            scheduled_at: scheduledAt,
            calendar_at: scheduledAt ?? '',
            schedule_mode:
                post.status === PostStatus.Scheduled
                    ? staysQueued
                        ? ScheduleMode.Queue
                        : ScheduleMode.Custom
                    : post.schedule_mode,
        };
    };

    const forget = (postId: string): void => {
        movedPosts.value = Object.fromEntries(
            Object.entries(movedPosts.value).filter(([id]) => id !== postId),
        );
    };

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
            return;
        }

        movedPosts.value = {
            ...movedPosts.value,
            [post.id]: movedPost(post, target, scheduledAt),
        };

        let failed = false;

        router.put(
            updatePostSchedule.url(post.id),
            { action: 'reschedule', scheduled_at: scheduledAt },
            {
                preserveScroll: true,
                preserveState: true,
                only: options.reloadProps,
                reset: ['undatedDrafts'],
                onError: (errors) => {
                    failed = true;
                    rollback(post.id, Object.values(errors)[0]);
                },
                onHttpException: () => {
                    failed = true;
                    rollback(post.id);

                    return false;
                },
                onFinish: () => {
                    forget(post.id);

                    if (!failed) {
                        options.onMoved?.();
                    }
                },
            },
        );
    };

    const stopMonitor = monitorForElements({
        canMonitor: ({ source }) => isCalendarPost(source.data),
        onDragStart: ({ source }) => {
            draggingPostId.value = posts.get(source.element)?.id ?? null;
            document.body.classList.add(GRABBING_CLASS);
        },
        onDrag: ({ location }) => placePreview(location.current.input),
        onDropTargetChange: ({ location }) => {
            overTarget.value = targetKeyOf(location.current.dropTargets[0]?.data);
        },
        onDrop: ({ source, location }) => {
            endDrag();

            const post = posts.get(source.element);
            const dropTarget = location.current.dropTargets[0];
            const target = dropTarget ? targets.get(dropTarget.element) : undefined;

            if (!post || !target) {
                return;
            }

            const scheduledAt = instantFor(post, target);

            if (scheduledAt !== undefined) {
                void move(post, target, scheduledAt);
            }
        },
    });

    onBeforeUnmount(() => {
        stopMonitor();
        endDrag();
    });

    const registerPost = (el: HTMLElement, post: PostCard): (() => void) => {
        posts.set(el, post);

        const cleanup = draggable({
            element: el,
            canDrag: () => {
                const current = posts.get(el);

                return current !== undefined && isDraggable(current);
            },
            getInitialData: () => ({ [POST_KEY]: true }),
            onGenerateDragPreview: ({ nativeSetDragImage, location }) => {
                disableNativeDragPreview({ nativeSetDragImage });
                showPreview(el, location.current.input);
            },
        });

        return () => {
            posts.delete(el);
            cleanup();
        };
    };

    const registerTarget = (
        el: HTMLElement,
        target: CalendarDropTarget,
    ): (() => void) => {
        targets.set(el, target);

        const cleanup = dropTargetForElements({
            element: el,
            canDrop: ({ source }) => {
                const post = posts.get(source.element);
                const current = targets.get(el);

                return (
                    post !== undefined &&
                    current !== undefined &&
                    isCalendarPost(source.data) &&
                    instantFor(post, current) !== undefined
                );
            },
            getData: () => {
                const current = targets.get(el);

                return {
                    [TARGET_KEY]: true,
                    key: current ? calendarTargetKey(current) : '',
                };
            },
        });

        return () => {
            targets.delete(el);
            cleanup();
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
        draggingPostId,
        movedPosts,
        vCalendarPost,
        vCalendarTarget,
    };
};
