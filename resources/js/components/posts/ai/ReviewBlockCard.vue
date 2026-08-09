<script setup lang="ts">
/* eslint-disable vue/no-mutating-props */
import { IconGripVertical } from '@tabler/icons-vue';
import { computed } from 'vue';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

export interface ReviewSlide {
    role?: string;
    title?: string;
    body?: string;
    tweet_text?: string;
    image_keywords?: string[];
}

const props = defineProps<{
    slide: ReviewSlide;
    index: number;
    isTweet?: boolean;
}>();

defineEmits<{
    (e: 'grab'): void;
    (e: 'release'): void;
}>();

// image_keywords is an array on the backend; it is edited here as comma-separated text.
const keywordsText = computed<string>({
    get: () => (props.slide.image_keywords ?? []).join(', '),
    set: (value: string) => {
        props.slide.image_keywords = value
            .split(',')
            .map((k) => k.trim())
            .filter(Boolean);
    },
});

// The generator labels each slide with its narrative role; the badge keeps that
// visible while reordering so the arc stays readable at a glance.
const roleStyles: Record<string, string> = {
    hook: 'bg-amber-200 text-amber-950',
    development: 'bg-sky-200 text-sky-950',
    proof: 'bg-teal-200 text-teal-950',
    cta: 'bg-foreground text-background',
};

const roleClass = computed(
    () =>
        roleStyles[props.slide.role ?? ''] ?? 'bg-muted text-muted-foreground',
);
</script>

<template>
    <div
        class="flex h-full flex-col gap-2 rounded-xl border-2 border-foreground bg-card p-2.5 shadow-2xs"
    >
        <div class="flex items-center justify-between gap-1">
            <div class="flex items-center gap-1">
                <button
                    type="button"
                    class="cursor-grab touch-none text-foreground/40 hover:text-foreground active:cursor-grabbing"
                    :aria-label="
                        $t('posts.ai_review.reorder_slide', {
                            number: String(index + 1),
                        })
                    "
                    @mousedown="$emit('grab')"
                    @mouseup="$emit('release')"
                    @touchstart="$emit('grab')"
                    @touchend="$emit('release')"
                >
                    <IconGripVertical class="size-4" />
                </button>
                <span
                    class="inline-flex size-6 items-center justify-center rounded-lg border-2 border-foreground bg-background text-xs font-black"
                >
                    {{ index + 1 }}
                </span>
            </div>
            <span
                v-if="slide.role"
                class="rounded-full px-2 py-0.5 text-[10px] font-black tracking-wide uppercase"
                :class="roleClass"
            >
                {{ slide.role }}
            </span>
        </div>

        <!-- Tweet card: just the tweet text. -->
        <template v-if="isTweet">
            <Textarea
                v-model="slide.tweet_text"
                class="min-h-64 flex-1 text-sm"
                style="resize: vertical; field-sizing: fixed"
                :placeholder="$t('posts.ai_review.field_tweet_text')"
            />
        </template>

        <!-- Image card: headline, body and the photo search keywords. -->
        <template v-else>
            <div class="space-y-0.5">
                <p
                    class="text-[10px] font-black tracking-widest text-foreground/60 uppercase"
                >
                    {{ $t('posts.ai_review.field_title') }}
                </p>
                <Input v-model="slide.title" class="h-8 text-sm" />
            </div>

            <div class="space-y-0.5 border-t-2 border-foreground/10 pt-2">
                <p
                    class="text-[10px] font-black tracking-widest text-foreground/60 uppercase"
                >
                    {{ $t('posts.ai_review.field_body') }}
                </p>
                <Textarea
                    v-model="slide.body"
                    class="min-h-32 text-sm"
                    style="resize: vertical; field-sizing: fixed"
                />
            </div>

            <div class="space-y-1 border-t-2 border-foreground/10 pt-2">
                <p
                    class="text-[10px] font-black tracking-widest text-foreground/60 uppercase"
                >
                    {{ $t('posts.ai_review.field_image_keywords') }}
                </p>
                <p class="text-[10px] leading-tight text-muted-foreground">
                    {{ $t('posts.ai_review.field_image_keywords_hint') }}
                </p>
                <Input
                    v-model="keywordsText"
                    class="h-8 text-sm"
                    :placeholder="
                        $t('posts.ai_review.field_image_keywords_placeholder')
                    "
                />
            </div>
        </template>
    </div>
</template>
