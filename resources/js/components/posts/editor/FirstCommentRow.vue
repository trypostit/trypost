<script setup lang="ts">
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Textarea } from '@/components/ui/textarea';
import { characterCount } from '@/lib/characters';
import { blank } from '@/lib/helpers';
import type { FirstCommentLimits } from '@/types/platform';

const props = withDefaults(
    defineProps<{
        accountId: string;
        limits: FirstCommentLimits;
        meta?: Record<string, any>;
        disabled?: boolean;
    }>(),
    { meta: () => ({}), disabled: false },
);

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const text = computed({
    get: (): string => props.meta?.first_comment?.text ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            first_comment: blank(value) ? null : { text: value },
        }),
});

const length = computed(() => characterCount(text.value.trim()));
const inputId = computed(() => `first-comment-${props.accountId}`);
</script>

<template>
    <SettingsSection>
        <SettingsRow
            :label="$t('posts.form.first_comment.label')"
            :label-for="inputId"
            align-top
        >
            <Textarea
                :id="inputId"
                v-model="text"
                :data-testid="`first-comment-${accountId}`"
                :placeholder="$t('posts.form.first_comment.placeholder')"
                :disabled="disabled"
                :aria-invalid="length > limits.maxLength || undefined"
            />
            <p
                class="text-end text-xs tabular-nums"
                :class="
                    length > limits.maxLength
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
                :data-testid="`first-comment-count-${accountId}`"
            >
                {{ length }}/{{ limits.maxLength }}
            </p>
        </SettingsRow>
    </SettingsSection>
</template>
