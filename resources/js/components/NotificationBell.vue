<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { IconArchive, IconBell, IconCheck, IconChecks, IconInbox, IconX } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import dayjs from '@/dayjs';
import { accounts } from '@/routes/app';
import { index, read, readAll, archiveAll } from '@/routes/app/notifications';
import { edit as editPost } from '@/routes/app/posts';
import type { SharedData } from '@/types';

interface Notification {
    id: string;
    type: string;
    title: string;
    body: string;
    data: Record<string, string> | null;
    read_at: string | null;
    archived_at: string | null;
    created_at: string;
}

const notifications = ref<Notification[]>([]);
const unreadCount = ref(0);
const loading = ref(false);
const show = ref(false);
const panel = ref<HTMLElement | null>(null);

const page = usePage<SharedData>();
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);
const currentWorkspaceId = computed(() => page.props.auth?.currentWorkspace?.id ?? null);

const channelName = computed(() =>
    currentUserId.value && currentWorkspaceId.value
        ? `workspace.${currentWorkspaceId.value}.user.${currentUserId.value}`
        : null,
);

if (channelName.value) {
    useEcho(channelName.value, '.notification.created', (e: { notification: Notification }) => {
        const exists = notifications.value.some((n) => n.id === e.notification.id);
        if (exists) return;

        notifications.value = [e.notification, ...notifications.value];
        if (! e.notification.read_at) {
            unreadCount.value += 1;
        }
    });
}

const csrfToken = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

const fetchNotifications = async () => {
    loading.value = true;
    try {
        const response = await fetch(index.url(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (! response.ok) return;

        const data = await response.json();
        notifications.value = data.notifications;
        unreadCount.value = data.unread_count;
    } finally {
        loading.value = false;
    }
};

const handleMarkAsRead = async (notification: Notification) => {
    await fetch(read.url(notification.id), {
        method: 'PUT',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
    });

    notification.read_at = dayjs().toISOString();
    unreadCount.value = Math.max(0, unreadCount.value - 1);
};

const handleMarkAllAsRead = async () => {
    await fetch(readAll.url(), {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
    });

    notifications.value = notifications.value.map((n) => ({
        ...n,
        read_at: n.read_at ?? dayjs().toISOString(),
    }));
    unreadCount.value = 0;
};

const handleArchiveAll = async () => {
    await fetch(archiveAll.url(), {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
    });

    notifications.value = [];
    unreadCount.value = 0;
};

const handleNotificationClick = (notification: Notification) => {
    if (!notification.read_at) {
        handleMarkAsRead(notification);
    }

    close();

    if (notification.type === 'mentioned_in_comment' && notification.data?.post_id) {
        const url = new URL(editPost.url(notification.data.post_id), window.location.origin);
        url.searchParams.set('tab', 'comments');
        if (notification.data?.comment_id) {
            url.searchParams.set('comment', notification.data.comment_id);
        }
        router.visit(url.toString());
        return;
    }

    if (notification.data?.post_id) {
        router.visit(editPost.url(notification.data.post_id));
    } else if (notification.data?.social_account_id || notification.data?.workspace_id) {
        router.visit(accounts.url());
    }
};

const open = () => {
    show.value = true;
    fetchNotifications();
};

const close = () => {
    show.value = false;
};

const toggle = () => {
    if (show.value) {
        close();
    } else {
        open();
    }
};

const onClickOutside = (event: MouseEvent) => {
    if (panel.value && !panel.value.contains(event.target as Node)) {
        close();
    }
};

const onEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        close();
    }
};

const formatTime = (date: string) => {
    return dayjs.utc(date).fromNow();
};

watch(show, (value) => {
    if (value) {
        setTimeout(() => {
            document.addEventListener('click', onClickOutside);
            document.addEventListener('keydown', onEscape);
        }, 0);
    } else {
        document.removeEventListener('click', onClickOutside);
        document.removeEventListener('keydown', onEscape);
    }
});

onMounted(() => {
    fetchNotifications();
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onClickOutside);
    document.removeEventListener('keydown', onEscape);
});
</script>

<template>
    <Button variant="ghost" size="icon" class="relative size-8 shrink-0" @click.stop="toggle">
        <IconBell class="size-4" />
        <span
            v-if="unreadCount > 0"
            class="absolute -right-1 -top-1 inline-flex size-4 items-center justify-center rounded-full border-2 border-foreground bg-rose-100 text-[9px] font-bold text-rose-700 shadow-2xs"
        >
            {{ unreadCount > 9 ? '9+' : unreadCount }}
        </span>
    </Button>

    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="-translate-x-2 opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-x-0 opacity-100"
            leave-to-class="-translate-x-2 opacity-0"
        >
            <div
                v-if="show"
                ref="panel"
                class="fixed inset-x-2 bottom-2 z-50 flex h-[32rem] max-h-[calc(100svh-1rem)] flex-col overflow-hidden rounded-xl border-2 border-foreground bg-card shadow-md sm:inset-x-auto sm:left-[17rem] sm:w-[22rem]"
            >
                <!-- Header -->
                <div class="flex items-center justify-between gap-2 border-b-2 border-foreground/10 px-4 py-3">
                    <h3 class="text-[11px] font-black uppercase tracking-widest text-foreground/60">
                        {{ $t('sidebar.notifications') }}
                    </h3>
                    <div class="flex items-center gap-1.5">
                        <Tooltip v-if="notifications.length > 0">
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    class="inline-flex size-7 max-sm:size-9 cursor-pointer items-center justify-center rounded-md border-2 border-foreground bg-card text-foreground shadow-2xs transition-all hover:bg-violet-100"
                                    @click="handleMarkAllAsRead"
                                >
                                    <IconChecks class="size-3.5" stroke-width="2.5" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>{{ $t('sidebar.mark_all_read') }}</TooltipContent>
                        </Tooltip>
                        <Tooltip v-if="notifications.length > 0">
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    class="inline-flex size-7 max-sm:size-9 cursor-pointer items-center justify-center rounded-md border-2 border-foreground bg-card text-foreground shadow-2xs transition-all hover:bg-violet-100"
                                    @click="handleArchiveAll"
                                >
                                    <IconArchive class="size-3.5" stroke-width="2.5" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>{{ $t('sidebar.archive_all') }}</TooltipContent>
                        </Tooltip>
                        <button
                            type="button"
                            class="inline-flex size-7 max-sm:size-9 cursor-pointer items-center justify-center rounded-full border-2 border-foreground bg-card text-foreground shadow-2xs transition-all hover:-rotate-90 hover:bg-rose-100"
                            @click="close"
                        >
                            <IconX class="size-3.5" stroke-width="2.5" />
                        </button>
                    </div>
                </div>

                <!-- Notification list -->
                <div class="flex-1 overflow-y-auto">
                    <div v-if="notifications.length > 0" class="divide-y-2 divide-dashed divide-foreground/15">
                        <button
                            v-for="notification in notifications"
                            :key="notification.id"
                            type="button"
                            class="flex w-full cursor-pointer items-start gap-2.5 px-3 py-3 text-left transition-colors hover:bg-foreground/5"
                            :class="!notification.read_at ? 'bg-violet-100/40' : ''"
                            @click="handleNotificationClick(notification)"
                        >
                            <span
                                class="mt-1.5 inline-block size-2 shrink-0 rounded-full"
                                :class="!notification.read_at ? 'bg-violet-500 ring-2 ring-violet-200' : 'bg-transparent'"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-foreground">{{ notification.title }}</p>
                                <p class="truncate text-xs text-foreground/70">{{ notification.body }}</p>
                                <p class="mt-0.5 text-[11px] font-medium text-foreground/50">{{ formatTime(notification.created_at) }}</p>
                            </div>
                            <div class="shrink-0" @click.stop>
                                <Tooltip v-if="!notification.read_at">
                                    <TooltipTrigger as-child>
                                        <button
                                            type="button"
                                            class="inline-flex size-7 max-sm:size-9 cursor-pointer items-center justify-center rounded-md text-foreground/60 transition-colors hover:bg-foreground/10 hover:text-foreground"
                                            @click="handleMarkAsRead(notification)"
                                        >
                                            <IconCheck class="size-3.5" stroke-width="2.5" />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent>{{ $t('sidebar.mark_as_read') }}</TooltipContent>
                                </Tooltip>
                            </div>
                        </button>
                    </div>

                    <!-- Empty state -->
                    <div v-else-if="!loading" class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center">
                        <div class="inline-flex size-12 -rotate-3 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-2xs">
                            <IconInbox class="size-6 text-foreground" stroke-width="2" />
                        </div>
                        <p class="text-base font-bold text-foreground" style="font-family: var(--font-display)">
                            {{ $t('sidebar.no_notifications') }}
                        </p>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
