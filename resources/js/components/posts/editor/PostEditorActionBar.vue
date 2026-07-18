<script setup lang="ts">
import PostEditorActions from '@/components/posts/editor/PostEditorActions.vue';

withDefaults(
    defineProps<{
        isReadOnly: boolean;
        isScheduled: boolean;
        canEdit?: boolean;
        isSaving: boolean;
        isSubmitting: boolean;
        isPostActionDisabled: boolean;
        postActionTooltip: string;
        pickTimeLabel: string;
    }>(),
    {
        canEdit: true,
    },
);

const hasPickedTime = defineModel<boolean>('hasPickedTime', { required: true });
const scheduledDateTime = defineModel<string>('scheduledDateTime', { required: true });

const emit = defineEmits<{
    (e: 'delete'): void;
    (e: 'unschedule'): void;
    (e: 'submit', status: string): void;
}>();
</script>

<template>
    <div
        v-if="canEdit && !isReadOnly"
        data-testid="editor-action-bar"
        class="sticky bottom-0 z-30 flex items-center justify-end gap-2 border-t-2 border-foreground bg-card px-4 py-3 lg:hidden"
    >
        <PostEditorActions
            :is-read-only="isReadOnly"
            :is-scheduled="isScheduled"
            :can-edit="canEdit"
            :is-saving="isSaving"
            :is-submitting="isSubmitting"
            :is-post-action-disabled="isPostActionDisabled"
            :post-action-tooltip="postActionTooltip"
            :pick-time-label="pickTimeLabel"
            v-model:has-picked-time="hasPickedTime"
            v-model:scheduled-date-time="scheduledDateTime"
            @delete="emit('delete')"
            @unschedule="emit('unschedule')"
            @submit="emit('submit', $event)"
        />
    </div>
</template>
