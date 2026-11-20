<script setup lang="ts">
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import PostDetailsMedia from '@/components/publish/PostDetailsMedia.vue';
import PostPollResults from '@/components/publish/PostPollResults.vue';
import type { ThreadPost } from '@/lib/postThread';
import type { PublishSocialAccount } from '@/types/publish';

defineProps<{
    account: PublishSocialAccount;
    /** The channel's name, as the card's header shows it. */
    name: string;
    /** Every post of the thread, the first one included. */
    posts: ThreadPost[];
    /** The post poll's test id; each reply poll's adds `-reply-{n}`. */
    pollTestId: string;
    testKey: string;
}>();
</script>

<template>
    <ol class="-mt-3 flex flex-col" :data-testid="`post-thread-${testKey}`">
        <li
            v-for="(post, index) in posts"
            :key="index"
            class="flex gap-3"
            :data-testid="`post-thread-${testKey}-${index}`"
        >
            <div class="flex w-9 shrink-0 flex-col">
                <ChannelAvatar
                    v-if="index > 0"
                    :status="account.status"
                    :account-id="account.id"
                    :platform="account.platform"
                    :src="account.avatar_url"
                    :verified="account.verified_badge"
                    :name="name"
                    ring="card"
                />
                <span
                    v-if="index < posts.length - 1"
                    aria-hidden="true"
                    class="ms-[15px] w-0.5 flex-1 rounded-full bg-border"
                    :class="{ 'mt-1': index > 0 }"
                />
            </div>
            <div
                class="flex min-w-0 flex-1 flex-col gap-2"
                :class="{
                    'pt-1': index === 0,
                    'pb-5': index < posts.length - 1,
                }"
            >
                <p
                    v-if="index > 0"
                    class="flex min-h-8 items-center truncate text-sm leading-tight font-emphasis text-foreground"
                >
                    {{ name }}
                </p>
                <p
                    v-if="post.text"
                    class="line-clamp-4 text-sm whitespace-pre-line text-foreground"
                >
                    {{ post.text }}
                </p>
                <PostDetailsMedia
                    v-if="post.media.length"
                    :items="post.media"
                    :bleed="false"
                    :test-key="`card-${testKey}-${index}`"
                />
                <PostPollResults
                    v-if="post.poll"
                    :poll="post.poll"
                    :test-id="`${pollTestId}${post.pollTestSuffix}`"
                />
            </div>
        </li>
    </ol>
</template>
