<script setup lang="ts">
import PreviewAvatar from '@/components/posts/previews/PreviewAvatar.vue';
import PostDetailsMedia from '@/components/publish/PostDetailsMedia.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import type { MediaItem } from '@/types/media';
import type { VerifiedBadge as VerifiedBadgeType } from '@/types/social-account';

defineProps<{
    account: {
        id: string;
        platform: string;
        display_name: string | null;
        display_label: string;
        username: string;
        handle_label?: string;
        avatar_url: string | null;
        verified_badge?: VerifiedBadgeType | null;
    };
    /** Every post of the thread in publishing order, the first post included. */
    posts: { text: string; media: MediaItem[] }[];
    testKey: string;
}>();

const emit = defineEmits<{ 'open-media': [items: MediaItem[], index: number] }>();

const openMedia = (items: MediaItem[], index: number): void => {
    emit('open-media', items, index);
};
</script>

<template>
    <ol class="flex flex-col gap-6" :data-testid="`thread-view-${testKey}`">
        <li
            v-for="(post, index) in posts"
            :key="index"
            class="relative flex items-start gap-3"
            :data-testid="`thread-view-post-${testKey}-${index}`"
        >
            <span
                v-if="index < posts.length - 1"
                aria-hidden="true"
                class="absolute start-[19px] top-[calc(2.5rem+4px)] -bottom-5 w-0.5 rounded-full bg-border"
                :data-testid="`thread-view-connector-${testKey}-${index}`"
            />
            <PreviewAvatar
                :account="{
                    ...account,
                    display_name: account.display_name ?? '',
                    handle_label: account.handle_label ?? account.username,
                }"
                class="size-10"
            />
            <div class="flex min-w-0 flex-1 flex-col gap-2">
                <div class="flex min-w-0 items-center gap-1 text-sm">
                    <span class="truncate font-semibold text-foreground">{{
                        account.display_label
                    }}</span>
                    <VerifiedBadge
                        v-if="account.verified_badge"
                        :badge="account.verified_badge"
                        class="size-4"
                    />
                    <span class="min-w-0 truncate text-muted-foreground"
                        >@{{ account.username || account.handle_label }}</span
                    >
                </div>
                <p
                    v-if="post.text"
                    class="-mt-1 text-sm break-words whitespace-pre-line text-foreground"
                >
                    {{ post.text }}
                </p>
                <PostDetailsMedia
                    v-if="post.media.length"
                    :items="post.media"
                    :bleed="false"
                    :test-key="`${testKey}-${index}`"
                    @open="openMedia(post.media, $event)"
                />
                <slot name="post-end" :index="index" />
            </div>
        </li>
    </ol>
</template>
