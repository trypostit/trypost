<script setup lang="ts">
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { ContentType } from '@/types/content-type';

interface Props {
    contentType: string;
    meta?: Record<string, any>;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
    meta: () => ({}),
});

const emit = defineEmits<{
    'update:meta': [meta: Record<string, any>];
}>();

const aspectRatios = [
    { value: '1:1', labelKey: 'posts.form.facebook.aspect.square' },
    { value: '4:5', labelKey: 'posts.form.facebook.aspect.portrait' },
    { value: '16:9', labelKey: 'posts.form.facebook.aspect.landscape' },
    { value: 'original', labelKey: 'posts.form.facebook.aspect.original' },
];

const isFeed = computed(() => props.contentType === ContentType.FacebookPost);

const selectedAspectRatio = computed({
    get: () => props.meta.aspect_ratio ?? 'original',
    set: (value: string) =>
        emit('update:meta', { ...props.meta, aspect_ratio: value }),
});
</script>

<template>
    <SettingsSection v-if="isFeed">
        <SettingsRow :label="$t('posts.form.facebook.aspect_label')">
            <RadioGroup
                v-model="selectedAspectRatio"
                :disabled="disabled"
                orientation="horizontal"
                :aria-label="$t('posts.form.facebook.aspect_label')"
                class="flex min-h-8 flex-wrap items-center gap-x-5 gap-y-2"
            >
                <label
                    v-for="ratio in aspectRatios"
                    :key="ratio.value"
                    class="flex cursor-pointer items-center gap-2 text-sm"
                >
                    <RadioGroupItem
                        :value="ratio.value"
                        :data-testid="`facebook-aspect-${ratio.value}`"
                    />
                    {{ $t(ratio.labelKey) }}
                </label>
            </RadioGroup>
        </SettingsRow>
    </SettingsSection>
</template>
