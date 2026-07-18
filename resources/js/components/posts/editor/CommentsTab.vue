<script setup lang="ts">
import {
    IconArrowBackUp,
    IconEdit,
    IconLoader2,
    IconMoodSmile,
    IconSend,
    IconTrash,
    IconX,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import CommentBody from '@/components/CommentBody.vue';
import MentionTextarea from '@/components/MentionTextarea.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import date from '@/date';
import dayjs from '@/dayjs';
import {
    index as fetchComments,
    store as storeComment,
    update as updateComment,
    destroy as destroyComment,
    react as reactComment,
} from '@/routes/app/posts/comments';

interface User {
    id: string;
    name: string;
    avatar_url?: string | null;
    profile_photo_url?: string | null;
}

interface Reaction {
    user_id: string;
    emoji: string;
}

interface Comment {
    id: string;
    body: string;
    user_id: string;
    parent_id: string | null;
    reactions: Reaction[];
    created_at: string;
    updated_at: string;
    user: User;
    replies?: Comment[];
}

interface PaginatedResponse {
    data: Comment[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    mentioned_users?: Record<string, string>;
}

const props = defineProps<{
    postId: string;
    currentUserId: string;
    highlightCommentId?: string | null;
}>();

const EMOJIS = ['👍', '❤️', '😂', '🎉', '🔥', '👏', '😍', '🤔', '👀', '💯'];

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

const comments = ref<Comment[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const loading = ref(false);
const sending = ref(false);

const newBody = ref('');
const replyingTo = ref<Comment | null>(null);
const editingComment = ref<Comment | null>(null);
const editBody = ref('');
const emojiPickerCommentId = ref<string | null>(null);

const scrollContainer = ref<HTMLDivElement | null>(null);
const textareaRef = ref<InstanceType<typeof MentionTextarea> | null>(null);

const hasOlderComments = computed(() => currentPage.value < lastPage.value);

interface DayGroup {
    label: string;
    comments: Comment[];
}

const commentsByDay = computed((): DayGroup[] => {
    const groups: Map<string, Comment[]> = new Map();

    for (const comment of comments.value) {
        const day = dayjs.utc(comment.created_at).local().format('YYYY-MM-DD');
        if (!groups.has(day)) groups.set(day, []);
        groups.get(day)!.push(comment);
    }

    const today = dayjs().format('YYYY-MM-DD');
    const yesterday = dayjs().subtract(1, 'day').format('YYYY-MM-DD');

    return Array.from(groups.entries()).map(([day, items]) => ({
        label: day === today
            ? trans('comments.today')
            : day === yesterday
              ? trans('comments.yesterday')
              : dayjs(day).format('D MMM YYYY'),
        comments: items,
    }));
});

const getInitials = (name: string): string => {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
};

const getAvatarUrl = (user: User): string | null => {
    return user.avatar_url || user.profile_photo_url || null;
};

const groupedReactions = (reactions: Reaction[]): { emoji: string; count: number; hasReacted: boolean }[] => {
    if (!reactions || reactions.length === 0) return [];
    const map = new Map<string, { count: number; hasReacted: boolean }>();
    for (const r of reactions) {
        const existing = map.get(r.emoji) || { count: 0, hasReacted: false };
        existing.count++;
        if (r.user_id === props.currentUserId) existing.hasReacted = true;
        map.set(r.emoji, existing);
    }
    return Array.from(map.entries()).map(([emoji, data]) => ({ emoji, ...data }));
};

const loadComments = async (page = 1) => {
    loading.value = true;
    try {
        const url = fetchComments.url(props.postId, { query: { page } });
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) return;
        const data: PaginatedResponse = await response.json();
        currentPage.value = data.current_page;
        lastPage.value = data.last_page;

        if (data.mentioned_users) {
            memberNames.value = { ...memberNames.value, ...data.mentioned_users };
        }

        if (page === 1) {
            // Reverse so newest is at bottom
            comments.value = [...data.data].reverse();
            await nextTick();
            scrollToBottom();
        } else {
            // Prepend older comments at top (also reversed)
            const older = [...data.data].reverse();
            comments.value = [...older, ...comments.value];
        }
    } finally {
        loading.value = false;
    }
};

const loadOlderComments = () => {
    if (hasOlderComments.value && !loading.value) {
        loadComments(currentPage.value + 1);
    }
};

const scrollToBottom = () => {
    if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
    }
};

const sendComment = async () => {
    const body = newBody.value.trim();
    if (!body || sending.value) return;

    sending.value = true;
    try {
        const payload: Record<string, string> = { body };
        if (replyingTo.value) {
            payload.parent_id = replyingTo.value.id;
        }

        const response = await fetch(storeComment.url(props.postId), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) return;

        const created: Comment = await response.json();

        if (created.parent_id) {
            // Add reply to parent
            const parent = comments.value.find((c) => c.id === created.parent_id);
            if (parent) {
                if (!parent.replies) parent.replies = [];
                parent.replies.push(created);
            }
        } else {
            // Add to bottom (newest)
            created.replies = [];
            comments.value.push(created);
        }

        newBody.value = '';
        replyingTo.value = null;
        await nextTick();
        scrollToBottom();
    } finally {
        sending.value = false;
    }
};

const startReply = (comment: Comment) => {
    replyingTo.value = comment;
    editingComment.value = null;
    nextTick(() => {
        const el = textareaRef.value?.$el as HTMLTextAreaElement | undefined;
        el?.focus();
    });
};

const cancelReply = () => {
    replyingTo.value = null;
};

const startEdit = (comment: Comment) => {
    editingComment.value = comment;
    editBody.value = comment.body;
    replyingTo.value = null;
};

const cancelEdit = () => {
    editingComment.value = null;
    editBody.value = '';
};

const saveEdit = async () => {
    if (!editingComment.value || !editBody.value.trim()) return;

    const comment = editingComment.value;
    try {
        const response = await fetch(
            updateComment.url({ post: props.postId, comment: comment.id }),
            {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ body: editBody.value.trim() }),
            },
        );

        if (!response.ok) return;

        const updated: Comment = await response.json();

        // Update in top-level or in replies
        const topIndex = comments.value.findIndex((c) => c.id === comment.id);
        if (topIndex !== -1) {
            comments.value[topIndex].body = updated.body;
            comments.value[topIndex].updated_at = updated.updated_at;
        } else {
            for (const parent of comments.value) {
                const replyIndex = parent.replies?.findIndex((r) => r.id === comment.id) ?? -1;
                if (replyIndex !== -1 && parent.replies) {
                    parent.replies[replyIndex].body = updated.body;
                    parent.replies[replyIndex].updated_at = updated.updated_at;
                    break;
                }
            }
        }

        cancelEdit();
    } catch {
        // ignore
    }
};

const deleteComment = async (comment: Comment) => {
    try {
        const response = await fetch(
            destroyComment.url({ post: props.postId, comment: comment.id }),
            {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        if (!response.ok) return;

        // Remove from top-level
        const topIndex = comments.value.findIndex((c) => c.id === comment.id);
        if (topIndex !== -1) {
            comments.value.splice(topIndex, 1);
        } else {
            // Remove from replies
            for (const parent of comments.value) {
                const replyIndex = parent.replies?.findIndex((r) => r.id === comment.id) ?? -1;
                if (replyIndex !== -1 && parent.replies) {
                    parent.replies.splice(replyIndex, 1);
                    break;
                }
            }
        }
    } catch {
        // ignore
    }
};

const toggleReaction = async (comment: Comment, emoji: string) => {
    emojiPickerCommentId.value = null;
    try {
        const response = await fetch(
            reactComment.url({ post: props.postId, comment: comment.id }),
            {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ emoji }),
            },
        );

        if (!response.ok) return;

        const updated: Comment = await response.json();

        // Update reactions in the right place
        const topIndex = comments.value.findIndex((c) => c.id === comment.id);
        if (topIndex !== -1) {
            comments.value[topIndex].reactions = updated.reactions;
        } else {
            for (const parent of comments.value) {
                const replyIndex = parent.replies?.findIndex((r) => r.id === comment.id) ?? -1;
                if (replyIndex !== -1 && parent.replies) {
                    parent.replies[replyIndex].reactions = updated.reactions;
                    break;
                }
            }
        }
    } catch {
        // ignore
    }
};

const showEmojiPicker = (commentId: string) => {
    emojiPickerCommentId.value = commentId;
};

const handleKeydown = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        sendComment();
    }
};

const handleEditKeydown = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        saveEdit();
    }
    if (event.key === 'Escape') {
        cancelEdit();
    }
};

const addCommentFromBroadcast = (comment: Comment) => {
    // Avoid duplicates
    if (comment.user_id === props.currentUserId) return;

    if (comment.parent_id) {
        const parent = comments.value.find((c) => c.id === comment.parent_id);
        if (parent) {
            const exists = parent.replies?.some((r) => r.id === comment.id);
            if (!exists) {
                if (!parent.replies) parent.replies = [];
                parent.replies.push(comment);
            }
        }
    } else {
        const exists = comments.value.some((c) => c.id === comment.id);
        if (!exists) {
            comment.replies = comment.replies || [];
            comments.value.push(comment);
            nextTick(() => scrollToBottom());
        }
    }
};

const memberNames = ref<Record<string, string>>({});

const registerMention = (member: { id: string; name: string }) => {
    memberNames.value = { ...memberNames.value, [member.id]: member.name };
};

const registerMentionedUsers = (users: Record<string, string>) => {
    memberNames.value = { ...memberNames.value, ...users };
};

defineExpose({ addCommentFromBroadcast, registerMentionedUsers });

const highlightedId = ref<string | null>(null);

const focusComment = async (commentId: string) => {
    await nextTick();
    const el = document.querySelector<HTMLElement>(`[data-comment-id="${commentId}"]`);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    highlightedId.value = commentId;
    setTimeout(() => {
        if (highlightedId.value === commentId) highlightedId.value = null;
    }, 4000);
};

onMounted(async () => {
    await loadComments(1);
    if (props.highlightCommentId) {
        await focusComment(props.highlightCommentId);
    }
});

watch(
    () => props.highlightCommentId,
    (id) => {
        if (id) void focusComment(id);
    },
);

watch(() => props.postId, () => {
    comments.value = [];
    currentPage.value = 1;
    loadComments(1);
});
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- Comment list (inverted scroll: newest at bottom) -->
        <div ref="scrollContainer" class="flex-1 overflow-y-auto">
            <!-- Load older button -->
            <div v-if="hasOlderComments" class="flex justify-center py-2">
                <Button variant="ghost" size="sm" :disabled="loading" @click="loadOlderComments">
                    <IconLoader2 v-if="loading" class="mr-1.5 h-3.5 w-3.5 animate-spin" />
                    {{ $t('comments.load_more') }}
                </Button>
            </div>

            <!-- Empty state -->
            <div v-if="!loading && comments.length === 0" class="flex flex-col items-center justify-center py-12 text-center">
                <p class="text-sm font-medium text-foreground/60">{{ $t('comments.empty') }}</p>
            </div>

            <!-- Comments grouped by day -->
            <div class="px-2 py-1">
                <template v-for="group in commentsByDay" :key="group.label">
                    <div class="mb-4 mt-2 text-center text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ group.label }}</div>

                <template v-for="comment in group.comments" :key="comment.id">
                    <!-- Top-level comment -->
                    <div
                        :data-comment-id="comment.id"
                        class="group relative rounded-lg py-1.5 px-2 transition-colors"
                        :class="highlightedId === comment.id ? 'bg-violet-100 ring-2 ring-foreground' : 'hover:bg-foreground/5'"
                        @mouseleave="emojiPickerCommentId = null"
                    >
                        <!-- Editing mode -->
                        <div v-if="editingComment?.id === comment.id" class="space-y-2">
                            <MentionTextarea
                                v-model="editBody"
                                :member-names="memberNames"
                                class="min-h-[60px] resize-none text-sm"
                                @keydown="handleEditKeydown"
                                @mention="registerMention"
                            />
                            <div class="flex items-center gap-1.5">
                                <Button size="sm" variant="default" @click="saveEdit">{{ $t('comments.save') }}</Button>
                                <Button size="sm" variant="ghost" @click="cancelEdit">{{ $t('comments.cancel') }}</Button>
                            </div>
                        </div>

                        <!-- Display mode -->
                        <template v-else>
                            <div class="flex items-start gap-3">
                                <Avatar class="size-8 shrink-0 rounded-full border-2 border-foreground shadow-2xs">
                                    <AvatarImage v-if="getAvatarUrl(comment.user)" :src="getAvatarUrl(comment.user)!" />
                                    <AvatarFallback class="rounded-full bg-violet-100 text-[10px] font-bold text-violet-700">{{ getInitials(comment.user.name) }}</AvatarFallback>
                                </Avatar>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span class="text-sm font-bold text-foreground">{{ comment.user.name }}</span>
                                        <TooltipProvider>
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <span class="text-xs font-medium text-foreground/60">{{ date.diffForHumans(comment.created_at) }}</span>
                                                </TooltipTrigger>
                                                <TooltipContent side="top">
                                                    <span class="text-xs">{{ date.formatDateTime(comment.created_at) }}</span>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                        <span v-if="comment.updated_at !== comment.created_at" class="text-xs font-medium italic text-foreground/60">({{ $t('comments.edited') }})</span>
                                    </div>
                                    <div class="mt-0.5 text-sm leading-relaxed text-foreground">
                                        <CommentBody :body="comment.body" :members="memberNames" />
                                    </div>

                                    <!-- Reactions -->
                                    <div v-if="groupedReactions(comment.reactions).length > 0" class="mt-1.5 flex flex-wrap gap-1">
                                        <button
                                            v-for="r in groupedReactions(comment.reactions)"
                                            :key="r.emoji"
                                            class="inline-flex items-center gap-1 rounded-full border-2 px-2 py-0.5 text-xs font-bold cursor-pointer transition-colors"
                                            :class="r.hasReacted ? 'border-foreground bg-violet-100' : 'border-foreground/30 hover:border-foreground'"
                                            @click="toggleReaction(comment, r.emoji)"
                                        >
                                            <span>{{ r.emoji }}</span>
                                            <span class="text-[10px] font-medium text-foreground/60">{{ r.count }}</span>
                                        </button>
                                    </div>

                                </div>

                                <!-- Floating toolbar -->
                                <div
                                    v-if="editingComment?.id !== comment.id"
                                    class="absolute -top-3 right-2 z-50 flex items-center gap-0.5 rounded-md border-2 border-foreground bg-card px-1 py-0.5 opacity-100 shadow-2xs transition-opacity lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100"
                                >
                                    <TooltipProvider :delay-duration="200">
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <button class="rounded p-1 text-muted-foreground hover:bg-foreground/5 hover:text-foreground" @click="showEmojiPicker(comment.id)"><IconMoodSmile class="h-3.5 w-3.5" /></button>
                                            </TooltipTrigger>
                                            <TooltipContent side="top" class="text-xs">React</TooltipContent>
                                        </Tooltip>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <button data-testid="comment-reply" class="rounded p-1 text-muted-foreground hover:bg-foreground/5 hover:text-foreground" @click="startReply(comment)"><IconArrowBackUp class="h-3.5 w-3.5" /></button>
                                            </TooltipTrigger>
                                            <TooltipContent side="top" class="text-xs">{{ $t('comments.reply') }}</TooltipContent>
                                        </Tooltip>
                                        <template v-if="comment.user_id === currentUserId">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <button class="rounded p-1 text-muted-foreground hover:bg-foreground/5 hover:text-foreground" @click="startEdit(comment)"><IconEdit class="h-3.5 w-3.5" /></button>
                                                </TooltipTrigger>
                                                <TooltipContent side="top" class="text-xs">{{ $t('comments.edit') }}</TooltipContent>
                                            </Tooltip>
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <button class="rounded p-1 text-muted-foreground hover:bg-rose-100 hover:text-rose-700" @click="deleteComment(comment)"><IconTrash class="h-3.5 w-3.5" /></button>
                                                </TooltipTrigger>
                                                <TooltipContent side="top" class="text-xs">{{ $t('comments.delete') }}</TooltipContent>
                                            </Tooltip>
                                        </template>
                                    </TooltipProvider>
                                </div>

                                <!-- Emoji picker -->
                                <div
                                    v-if="emojiPickerCommentId === comment.id"
                                    class="absolute -top-10 right-2 z-50 flex items-center gap-0.5 rounded-lg border-2 border-foreground bg-card p-1.5 shadow-md"                                >
                                    <button v-for="emoji in EMOJIS" :key="emoji" class="rounded p-0.5 text-sm transition-transform hover:scale-125 hover:bg-muted" @click="toggleReaction(comment, emoji)">{{ emoji }}</button>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Replies (1 level) -->
                    <template v-if="comment.replies && comment.replies.length > 0">
                        <div
                            v-for="reply in comment.replies"
                            :key="reply.id"
                            :data-comment-id="reply.id"
                            class="group relative ml-8 rounded-lg py-1.5 px-2 transition-colors"
                            :class="highlightedId === reply.id ? 'bg-violet-100 ring-2 ring-foreground' : 'hover:bg-foreground/5'"
                            @mouseleave="emojiPickerCommentId = null"
                        >
                            <!-- Editing reply -->
                            <div v-if="editingComment?.id === reply.id" class="space-y-2">
                                <MentionTextarea
                                    v-model="editBody"
                                    :member-names="memberNames"
                                    class="min-h-[60px] resize-none text-sm"
                                    @keydown="handleEditKeydown"
                                    @mention="registerMention"
                                />
                                <div class="flex items-center gap-1.5">
                                    <Button size="sm" variant="default" @click="saveEdit">{{ $t('comments.save') }}</Button>
                                    <Button size="sm" variant="ghost" @click="cancelEdit">{{ $t('comments.cancel') }}</Button>
                                </div>
                            </div>

                            <!-- Display reply -->
                            <template v-else>
                                <div class="flex items-start gap-2.5">
                                    <Avatar class="size-7 shrink-0 rounded-full border-2 border-foreground shadow-2xs">
                                        <AvatarImage v-if="getAvatarUrl(reply.user)" :src="getAvatarUrl(reply.user)!" />
                                        <AvatarFallback class="rounded-full bg-violet-100 text-[10px] font-bold text-violet-700">{{ getInitials(reply.user.name) }}</AvatarFallback>
                                    </Avatar>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                            <span class="text-sm font-bold text-foreground">{{ reply.user.name }}</span>
                                            <TooltipProvider>
                                                <Tooltip>
                                                    <TooltipTrigger as-child>
                                                        <span class="text-xs font-medium text-foreground/60">{{ date.diffForHumans(reply.created_at) }}</span>
                                                    </TooltipTrigger>
                                                    <TooltipContent side="top">
                                                        <span class="text-xs">{{ date.formatDateTime(reply.created_at) }}</span>
                                                    </TooltipContent>
                                                </Tooltip>
                                            </TooltipProvider>
                                            <span v-if="reply.updated_at !== reply.created_at" class="text-xs font-medium italic text-foreground/60">({{ $t('comments.edited') }})</span>
                                        </div>
                                        <div class="mt-0.5 text-sm leading-relaxed text-foreground">
                                            <CommentBody :body="reply.body" :members="memberNames" />
                                        </div>

                                        <!-- Reply reactions -->
                                        <div v-if="groupedReactions(reply.reactions).length > 0" class="mt-1.5 flex flex-wrap gap-1">
                                            <button
                                                v-for="r in groupedReactions(reply.reactions)"
                                                :key="r.emoji"
                                                class="inline-flex items-center gap-1 rounded-full border-2 px-2 py-0.5 text-xs font-bold cursor-pointer transition-colors"
                                                :class="r.hasReacted ? 'border-foreground bg-violet-100' : 'border-foreground/30 hover:border-foreground'"
                                                @click="toggleReaction(reply, r.emoji)"
                                            >
                                                <span>{{ r.emoji }}</span>
                                                <span class="text-[10px] font-medium text-foreground/60">{{ r.count }}</span>
                                            </button>
                                        </div>

                                    </div>

                                    <!-- Reply floating toolbar -->
                                    <div
                                        v-if="editingComment?.id !== reply.id"
                                        class="absolute -top-3 right-2 z-50 flex items-center gap-0.5 rounded-md border-2 border-foreground bg-card px-1 py-0.5 opacity-100 shadow-2xs transition-opacity lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100"
                                    >
                                        <TooltipProvider :delay-duration="200">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <button class="rounded p-1 text-muted-foreground hover:bg-foreground/5 hover:text-foreground" @click="showEmojiPicker(reply.id)"><IconMoodSmile class="h-3.5 w-3.5" /></button>
                                                </TooltipTrigger>
                                                <TooltipContent side="top" class="text-xs">React</TooltipContent>
                                            </Tooltip>
                                            <template v-if="reply.user_id === currentUserId">
                                                <Tooltip>
                                                    <TooltipTrigger as-child>
                                                        <button class="rounded p-1 text-muted-foreground hover:bg-foreground/5 hover:text-foreground" @click="startEdit(reply)"><IconEdit class="h-3.5 w-3.5" /></button>
                                                    </TooltipTrigger>
                                                    <TooltipContent side="top" class="text-xs">{{ $t('comments.edit') }}</TooltipContent>
                                                </Tooltip>
                                                <Tooltip>
                                                    <TooltipTrigger as-child>
                                                        <button class="rounded p-1 text-muted-foreground hover:bg-rose-100 hover:text-rose-700" @click="deleteComment(reply)"><IconTrash class="h-3.5 w-3.5" /></button>
                                                    </TooltipTrigger>
                                                    <TooltipContent side="top" class="text-xs">{{ $t('comments.delete') }}</TooltipContent>
                                                </Tooltip>
                                            </template>
                                        </TooltipProvider>
                                    </div>

                                    <!-- Reply emoji picker -->
                                    <div
                                        v-if="emojiPickerCommentId === reply.id"
                                        class="absolute -top-10 right-2 z-50 flex items-center gap-0.5 rounded-lg border-2 border-foreground bg-card p-1.5 shadow-md"                                    >
                                        <button v-for="emoji in EMOJIS" :key="emoji" class="rounded p-0.5 text-sm transition-transform hover:scale-125 hover:bg-muted" @click="toggleReaction(reply, emoji)">{{ emoji }}</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </template>
                </template>
            </div>

            <!-- Loading spinner for initial load -->
            <div v-if="loading && comments.length === 0" class="flex items-center justify-center py-8">
                <IconLoader2 class="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
        </div>

        <!-- Input area -->
        <div class="shrink-0 border-t-2 border-foreground/10 p-2">
            <!-- Replying to indicator -->
            <div v-if="replyingTo" class="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-foreground/70">
                <IconArrowBackUp class="size-3" />
                <span>{{ $t('comments.replying_to', { name: replyingTo.user.name }) }}</span>
                <button class="ml-auto cursor-pointer rounded p-0.5 hover:bg-foreground/5" @click="cancelReply">
                    <IconX class="size-3" />
                </button>
            </div>

            <div class="flex items-end gap-1.5">
                <MentionTextarea
                    ref="textareaRef"
                    v-model="newBody"
                    :member-names="memberNames"
                    :placeholder="replyingTo ? $t('comments.reply_placeholder') : $t('comments.placeholder')"
                    class="min-h-10 max-h-[120px] flex-1 resize-none text-sm"
                    :rows="1"
                    @keydown="handleKeydown"
                    @mention="registerMention"
                />
                <Button
                    size="icon"
                    class="shrink-0"
                    :disabled="!newBody.trim() || sending"
                    @click="sendComment"
                >
                    <IconLoader2 v-if="sending" class="size-4 animate-spin" />
                    <IconSend v-else class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
