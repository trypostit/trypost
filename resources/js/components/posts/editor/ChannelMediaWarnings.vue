<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed } from 'vue';

import MediaRulesWarning from '@/components/posts/editor/MediaRulesWarning.vue';
import { getMediaValidationWarning } from '@/composables/useMedia';
import { getInstagramImageAspectIssues } from '@/lib/instagramImageAspect';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';

const RULES_WARNING_PLATFORMS: string[] = [
    Platform.Instagram,
    Platform.InstagramFacebook,
    Platform.Facebook,
    Platform.Pinterest,
];

const ASPECT_KEYS = ['aspect_ratio_too_narrow', 'aspect_ratio_too_wide'];

const props = withDefaults(
    defineProps<{
        platform: string;
        contentType: string;
        media: MediaItem[];
        disabled?: boolean;
        mediaEditing?: boolean;
    }>(),
    { disabled: false, mediaEditing: false },
);

const emit = defineEmits<{ 'edit:media': [index: number] }>();

const isInstagram = computed(
    () =>
        props.platform === Platform.Instagram ||
        props.platform === Platform.InstagramFacebook,
);

const aspectIssues = computed(() =>
    isInstagram.value
        ? getInstagramImageAspectIssues(props.contentType, props.media)
        : [],
);

const hiddenKeys = computed(() =>
    aspectIssues.value.length ? ASPECT_KEYS : [],
);

const hasRulesWarning = computed(() => {
    if (!RULES_WARNING_PLATFORMS.includes(props.platform)) {
        return false;
    }

    const warning = getMediaValidationWarning(props.contentType, props.media);

    return (
        !!warning &&
        warning.key !== 'requires_media' &&
        !hiddenKeys.value.includes(warning.key)
    );
});
</script>

<template>
    <div
        v-if="aspectIssues.length || hasRulesWarning"
        class="space-y-2"
        data-testid="channel-media-warnings"
    >
        <div
            v-for="issue in aspectIssues"
            :key="issue.index"
            role="status"
            class="flex items-start gap-2 rounded-md bg-warning/15 px-3 py-1.5 text-sm"
            :data-testid="`instagram-image-aspect-issue-${issue.index}`"
        >
            <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
            <div class="min-w-0 flex-1">
                {{
                    $t('posts.composer.instagram_image_aspect_issue', {
                        image: String(issue.index + 1),
                        current: issue.ratio.toFixed(2),
                        min: issue.min.toFixed(2),
                        max: issue.max.toFixed(2),
                    })
                }}
                <button
                    v-if="mediaEditing"
                    type="button"
                    class="ml-1 font-medium underline underline-offset-2"
                    :data-testid="`instagram-edit-image-${issue.index}`"
                    :disabled="disabled"
                    @click="emit('edit:media', issue.index)"
                >
                    {{ $t('posts.composer.adjust_image') }}
                </button>
            </div>
        </div>
        <MediaRulesWarning
            v-if="hasRulesWarning"
            :content-type="contentType"
            :media="media"
            :platform="isInstagram ? Platform.Instagram : platform"
            :hidden-keys="hiddenKeys"
        />
    </div>
</template>
