<script setup lang="ts">
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Input } from '@/components/ui/input';
import { THREADS_TOPIC_TAG_MAX } from '@/types/network-options';

const props = withDefaults(
    defineProps<{ meta?: Record<string, any>; disabled?: boolean }>(),
    { meta: () => ({}), disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const topicTag = computed({
    get: (): string => props.meta.topic_tag ?? '',
    set: (value: string) => {
        const cleaned = value
            .replace(/^#+/, '')
            .replace(/[.&]/g, '')
            .slice(0, THREADS_TOPIC_TAG_MAX);

        emit('update:meta', {
            ...props.meta,
            topic_tag: cleaned.trim() ? cleaned : null,
        });
    },
});
</script>

<template>
    <SettingsSection>
        <SettingsRow>
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.threads.topic')
                }}</span>
            </template>
            <Input
                :aria-label="$t('posts.form.threads.topic')"
                v-model="topicTag"
                data-testid="threads-topic-tag"
                :disabled="disabled"
                :placeholder="$t('posts.form.threads.topic_placeholder')"
            />
        </SettingsRow>
    </SettingsSection>
</template>
