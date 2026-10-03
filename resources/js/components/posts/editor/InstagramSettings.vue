<script setup lang="ts">
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Switch } from '@/components/ui/switch';
import { ContentType } from '@/types/content-type';

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
</script>

<template>
    <SettingsSection v-if="isReel">
        <SettingsRow :label="$t('posts.form.instagram.share_to_feed')">
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.instagram.share_to_feed')
                }}</span>
            </template>
            <div class="flex min-h-8 items-center">
                <Switch
                    v-model="shareToFeed"
                    size="sm"
                    data-testid="instagram-share-to-feed"
                    :disabled="disabled"
                    :aria-label="$t('posts.form.instagram.share_to_feed')"
                />
            </div>
        </SettingsRow>
    </SettingsSection>
</template>
