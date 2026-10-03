<script setup lang="ts">
import { IconAlertTriangle, IconExternalLink } from '@tabler/icons-vue';
import { computed } from 'vue';

import {
    getMediaValidationWarning,
    mediaWarningParams,
} from '@/composables/useMedia';
import { mediaLimitsDocsUrl } from '@/lib/docs';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    contentType: string;
    media: MediaItem[];
    platform: string;
    hiddenKeys?: string[];
}>();

const warning = computed(() => {
    const found = getMediaValidationWarning(props.contentType, props.media);

    return found &&
        found.key !== 'requires_media' &&
        !props.hiddenKeys?.includes(found.key)
        ? found
        : null;
});
</script>

<template>
    <p
        v-if="warning"
        role="status"
        class="flex items-start gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
        data-testid="media-rules-warning"
    >
        <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
        <span>
            {{
                $t(
                    `posts.form.warnings.${warning.key}`,
                    mediaWarningParams(warning, contentType, $t),
                )
            }}
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
