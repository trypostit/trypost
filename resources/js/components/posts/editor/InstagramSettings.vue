<script setup lang="ts">
import { computed } from 'vue';

import CharacterCounter from '@/components/CharacterCounter.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ContentType } from '@/types/content-type';
import { toNullableText } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        contentType: string;
        meta?: Record<string, any>;
        disabled?: boolean;
    }>(),
    { meta: () => ({}), disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const isReel = computed(() => props.contentType === ContentType.InstagramReel);
const shareToFeed = computed({
    get: (): boolean => props.meta.share_to_feed ?? true,
    set: (value: boolean) =>
        emit('update:meta', { ...props.meta, share_to_feed: value }),
});

// Stories have no comments, so the first-comment field hides there.
const isStory = computed(
    () => props.contentType === ContentType.InstagramStory,
);
const FIRST_COMMENT_MAX = 2200;
const firstComment = computed({
    get: () => toNullableText(props.meta.first_comment) ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            first_comment: toNullableText(value),
        }),
});
</script>

<template>
    <SettingsSection v-if="isReel || !isStory">
        <SettingsRow v-if="isReel" :label="$t('posts.form.instagram.share_to_feed')">
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.instagram.share_to_feed')
                }}</span>
            </template>
            <div class="flex min-h-8 items-center">
                <Switch
                    v-model="shareToFeed"
                    data-testid="instagram-share-to-feed"
                    :disabled="disabled"
                    :aria-label="$t('posts.form.instagram.share_to_feed')"
                />
            </div>
        </SettingsRow>
        <SettingsRow
            v-if="!isStory"
            :label="$t('posts.form.first_comment.label')"
            label-for="instagram-first-comment"
            align-top
        >
            <Textarea
                id="instagram-first-comment"
                v-model="firstComment"
                data-testid="instagram-first-comment"
                :disabled="disabled"
                :maxlength="FIRST_COMMENT_MAX"
                :placeholder="$t('posts.form.first_comment.placeholder')"
                class="field-sizing-fixed min-h-20 w-full resize-y"
            />
            <CharacterCounter
                class="block"
                :exceeded="firstComment.length > FIRST_COMMENT_MAX"
                data-testid="instagram-first-comment-count"
            >
                {{ firstComment.length }}/{{ FIRST_COMMENT_MAX }}
            </CharacterCounter>
            <p class="text-xs text-foreground/60">
                {{ $t('posts.form.first_comment.hint_instagram') }}
            </p>
        </SettingsRow>
    </SettingsSection>
</template>
