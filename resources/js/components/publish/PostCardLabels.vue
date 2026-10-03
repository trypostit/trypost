<script setup lang="ts">
import { IconTag } from '@tabler/icons-vue';
import { computed, inject, ref, watch } from 'vue';

import LabelBadge from '@/components/labels/LabelBadge.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import {
    postCardLabelsKey,
    syncPostCardLabels,
} from '@/composables/usePostCardActions';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import type { PostCardLabel } from '@/types/publish';

const MAX_VISIBLE = 3;

const props = defineProps<{
    postId: string;
    labels: PostCardLabel[];
    testKey: string;
}>();

const { canCreatePost } = useWorkspaceAbilities();
const workspaceLabels = inject(
    postCardLabelsKey,
    computed(() => []),
);

const toIds = (labels: PostCardLabel[]): string[] =>
    labels.map((label) => label.id);

const selectedIds = ref<string[]>(toIds(props.labels));

watch(
    () => props.labels,
    (labels) => {
        selectedIds.value = toIds(labels);
    },
);

const assigned = computed<PostCardLabel[]>(() => {
    const known = new Map(
        [...props.labels, ...workspaceLabels.value].map((label) => [
            label.id,
            label,
        ]),
    );

    return selectedIds.value
        .map((id) => known.get(id))
        .filter((label): label is PostCardLabel => label !== undefined);
});

const visible = computed(() => assigned.value.slice(0, MAX_VISIBLE));
const hiddenCount = computed(() =>
    Math.max(0, assigned.value.length - MAX_VISIBLE),
);

const update = (ids: string[]): void => {
    const previous = selectedIds.value;

    selectedIds.value = ids;
    syncPostCardLabels(props.postId, ids, () => {
        selectedIds.value = previous;
    });
};
</script>

<template>
    <div
        v-if="canCreatePost || assigned.length"
        class="flex min-w-0"
        :data-testid="`post-labels-${testKey}`"
    >
        <LabelFilter
            v-if="canCreatePost"
            :model-value="selectedIds"
            :labels="workspaceLabels"
            :test-id="`post-labels-${testKey}`"
            :show-untagged="false"
            align="start"
            @update:model-value="update"
        >
            <template #trigger="{ open }">
                <button
                    type="button"
                    class="group flex min-w-0 flex-wrap items-center gap-1.5 rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :aria-label="$t('posts.publish.actions.edit_labels')"
                    :aria-expanded="open"
                    :data-testid="`post-labels-${testKey}-trigger`"
                >
                    <LabelBadge
                        v-for="label in visible"
                        :key="label.id"
                        :label="label"
                        :data-testid="`post-label-chip-${testKey}-${label.id}`"
                    />
                    <span
                        v-if="hiddenCount"
                        class="text-xs text-muted-foreground"
                        >+{{ hiddenCount }}</span
                    >
                    <span
                        class="inline-flex size-6 shrink-0 items-center justify-center rounded-md border border-border-strong text-muted-foreground transition-control group-hover:bg-accent group-hover:text-foreground"
                        :class="{ 'bg-accent text-foreground': open }"
                    >
                        <IconTag class="size-3.5" aria-hidden="true" />
                    </span>
                </button>
            </template>
        </LabelFilter>
        <div v-else class="flex flex-wrap items-center gap-1.5">
            <LabelBadge
                v-for="label in visible"
                :key="label.id"
                :label="label"
                :data-testid="`post-label-chip-${testKey}-${label.id}`"
            />
            <span v-if="hiddenCount" class="text-xs text-muted-foreground"
                >+{{ hiddenCount }}</span
            >
        </div>
    </div>
</template>
