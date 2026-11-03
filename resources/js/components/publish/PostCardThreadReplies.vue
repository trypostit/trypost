<script setup lang="ts">
import { IconPlayerPlayFilled } from '@tabler/icons-vue';

import { isImage } from '@/lib/mediaType';
import type { ThreadReply } from '@/lib/threadReplies';
import { videoFrameUrl } from '@/lib/videoFrame';

defineProps<{
    /** The replies under the post, without the empty ones. */
    replies: ThreadReply[];
    testKey: string;
}>();
</script>

<template>
    <ol
        class="mt-3 flex flex-col gap-2 border-s-2 border-border ps-3"
        :data-testid="`post-thread-${testKey}`"
    >
        <li
            v-for="(reply, index) in replies"
            :key="reply.key"
            class="flex items-start gap-2"
            :aria-label="
                $t('posts.form.thread.reply_label', {
                    number: String(index + 2),
                })
            "
            :data-testid="`post-thread-reply-${testKey}-${index}`"
        >
            <p
                class="line-clamp-2 min-w-0 flex-1 text-sm whitespace-pre-line text-foreground"
            >
                {{ reply.text }}
            </p>
            <div v-if="reply.media.length" class="flex shrink-0 gap-1">
                <span
                    v-for="item in reply.media"
                    :key="item.id"
                    class="relative block size-10 overflow-hidden rounded-md border border-border-strong bg-secondary"
                >
                    <img
                        v-if="isImage(item)"
                        :src="item.url"
                        alt=""
                        class="size-full object-cover"
                        loading="lazy"
                    />
                    <template v-else>
                        <video
                            :src="videoFrameUrl(item)"
                            class="size-full object-cover"
                            muted
                            playsinline
                            preload="metadata"
                        />
                        <IconPlayerPlayFilled
                            aria-hidden="true"
                            class="absolute top-1/2 left-1/2 size-4 -translate-x-1/2 -translate-y-1/2 text-white"
                        />
                    </template>
                </span>
            </div>
        </li>
    </ol>
</template>
