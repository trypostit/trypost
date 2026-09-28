<script setup lang="ts">
import { IconAlertTriangle, IconExternalLink } from '@tabler/icons-vue';
import { computed } from 'vue';

import { getMediaValidationWarning } from '@/composables/useMedia';
import { mediaLimitsDocsUrl } from '@/lib/docs';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    contentType: string;
    media: MediaItem[];
    platform: string;
}>();

const warning = computed(() => getMediaValidationWarning(props.contentType, props.media));
</script>

<template>
    <p
        v-if="warning"
        class="flex items-start gap-2 rounded-lg border-2 border-foreground bg-rose-50 p-2 text-xs font-semibold text-rose-700"
        data-testid="media-rules-warning"
    >
        <IconAlertTriangle class="mt-0.5 size-3.5 shrink-0" />
        <span>
            {{ $t(`posts.form.warnings.${warning.key}`, warning.params) }}
            <a
                :href="mediaLimitsDocsUrl(platform)"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-0.5 underline underline-offset-2"
            >
                {{ $t('posts.edit.compliance.media_limits_docs') }}
                <IconExternalLink class="size-3" />
            </a>
        </span>
    </p>
</template>
