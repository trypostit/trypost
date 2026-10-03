<script setup lang="ts">
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Input } from '@/components/ui/input';

const props = withDefaults(
    defineProps<{ meta?: Record<string, any>; disabled?: boolean }>(),
    { meta: () => ({}), disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const contentWarning = computed({
    get: (): string => props.meta.spoiler_text ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            spoiler_text: value.trim() ? value : null,
        }),
});
</script>

<template>
    <SettingsSection>
        <SettingsRow>
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.mastodon.content_warning')
                }}</span>
            </template>
            <Input
                :aria-label="$t('posts.form.mastodon.content_warning')"
                v-model="contentWarning"
                data-testid="mastodon-content-warning"
                :disabled="disabled"
                :placeholder="
                    $t('posts.form.mastodon.content_warning_placeholder')
                "
            />
        </SettingsRow>
    </SettingsSection>
</template>
