<script setup lang="ts">
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Input } from '@/components/ui/input';
import { isDocumentMedia } from '@/composables/useMedia';
import type { MediaItem } from '@/types/media';

interface Props {
    accountId: string;
    media: MediaItem[];
    meta?: Record<string, any>;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    meta: () => ({}),
    disabled: false,
});

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const pdfDocument = computed(
    () => props.media.find((item) => isDocumentMedia(item)) ?? null,
);

const documentTitle = computed({
    get: () =>
        (props.meta?.document_title as string | undefined) ||
        pdfDocument.value?.original_filename ||
        '',
    set: (value: string) =>
        emit('update:meta', { ...props.meta, document_title: value || null }),
});

const inputId = computed(() => `linkedin-document-title-${props.accountId}`);
</script>

<template>
    <SettingsSection v-if="pdfDocument">
        <SettingsRow
            :label="$t('posts.form.linkedin.document_title')"
            :label-for="inputId"
        >
            <Input
                :id="inputId"
                v-model="documentTitle"
                type="text"
                data-testid="linkedin-document-title"
                :placeholder="
                    $t('posts.form.linkedin.document_title_placeholder')
                "
                :disabled="disabled"
            />
        </SettingsRow>
    </SettingsSection>
</template>
