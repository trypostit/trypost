<script setup lang="ts">
import { computed } from 'vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Input } from '@/components/ui/input';
import { toNullableText } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        meta?: Record<string, any>;
        disabled?: boolean;
    }>(),
    { meta: () => ({}), disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const locationId = computed({
    get: () => toNullableText(props.meta.location_id) ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            location_id: toNullableText(value.trim()),
        }),
});
const locationName = computed({
    get: () => toNullableText(props.meta.location_name) ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            location_name: toNullableText(value),
        }),
});
</script>

<template>
    <SettingsSection>
        <SettingsRow
            :label="$t('posts.form.location.label')"
            label-for="facebook-location-id"
            align-top
        >
            <div class="grid grid-cols-2 gap-3">
                <Input
                    id="facebook-location-id"
                    v-model="locationId"
                    data-testid="facebook-location-id"
                    type="text"
                    :placeholder="$t('posts.form.location.id_placeholder')"
                    :disabled="disabled"
                />
                <Input
                    v-model="locationName"
                    data-testid="facebook-location-name"
                    type="text"
                    :placeholder="$t('posts.form.location.name_placeholder')"
                    :disabled="disabled"
                />
            </div>
            <p class="text-xs text-foreground/60">
                {{ $t('posts.form.location.hint') }}
            </p>
        </SettingsRow>
    </SettingsSection>
</template>
