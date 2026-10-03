<script setup lang="ts">
import {
    IconColumns3,
    IconLayoutGrid,
    IconLayoutCards,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import LabelFilter from '@/components/labels/LabelFilter.vue';
import MultiSelectFilter from '@/components/MultiSelectFilter.vue';
import { Checkbox } from '@/components/ui/checkbox';
import type { IdeaStage, IdeaLabel, IdeasView } from '@/types/idea';

const props = defineProps<{
    view: IdeasView;
    stages: IdeaStage[];
    labels: IdeaLabel[];
}>();

const emit = defineEmits<{ changeView: [view: IdeasView] }>();

const stageIds = defineModel<string[]>('stageIds', { required: true });
const labelIds = defineModel<string[]>('labelIds', { required: true });
const untagged = defineModel<boolean>('untagged', { required: true });
const unassigned = defineModel<boolean>('unassigned', { required: true });

const clearUnassigned = (): void => {
    unassigned.value = false;
};

const matches = (text: string, search: string): boolean =>
    text.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase());

const stageOptions = computed(() =>
    props.stages.map((stage) => ({ id: stage.id, label: stage.name })),
);

const views: { key: IdeasView; label: string; icon: typeof IconColumns3 }[] = [
    { key: 'board', label: 'create.ideas.board', icon: IconColumns3 },
    { key: 'gallery', label: 'create.ideas.gallery', icon: IconLayoutGrid },
];
</script>

<template>
    <div class="flex items-center gap-2" data-testid="ideas-toolbar">
        <MultiSelectFilter
            v-if="view === 'gallery'"
            v-model="stageIds"
            :options="stageOptions"
            :label="$t('create.ideas.stages_filter')"
            :search-placeholder="$t('create.ideas.stages_search')"
            :empty-message="$t('create.ideas.no_stages')"
            :select-all-label="$t('posts.composer.select_all')"
            :deselect-all-label="$t('posts.composer.deselect_all')"
            test-id="ideas-filter-stages"
            checkbox-position="start"
            :extra-count="unassigned ? 1 : 0"
            compact
            @clear="clearUnassigned"
        >
            <template #icon>
                <IconLayoutCards class="size-4" />
            </template>
            <template #before-options="{ search }">
                <label
                    v-if="matches($t('create.ideas.unassigned'), search)"
                    class="flex min-h-8 cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 text-sm leading-5 transition-control hover:bg-accent"
                    :class="{ 'bg-accent': unassigned }"
                    data-testid="ideas-filter-stages-unassigned"
                >
                    <Checkbox
                        v-model="unassigned"
                        data-testid="ideas-filter-stages-unassigned-checkbox"
                    />
                    <span
                        class="flex min-w-0 flex-1 items-center gap-2.5 text-foreground"
                    >
                        <span
                            class="size-2.5 shrink-0 rounded-full border border-dashed border-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{
                            $t('create.ideas.unassigned')
                        }}</span>
                    </span>
                </label>
            </template>
        </MultiSelectFilter>

        <LabelFilter
            v-model="labelIds"
            v-model:untagged="untagged"
            :labels="labels"
            test-id="ideas-filter-labels"
        />

        <div
            class="inline-flex h-8 items-center gap-1 rounded-lg border border-border-strong bg-card p-[3px]"
            role="group"
            :aria-label="$t('create.ideas.view_switcher')"
        >
            <button
                v-for="option in views"
                :key="option.key"
                type="button"
                :aria-pressed="view === option.key"
                :data-testid="`ideas-view-${option.key}`"
                class="inline-flex h-6 items-center justify-center gap-1 rounded-md border border-transparent px-2 text-sm font-medium transition-control"
                :class="
                    view === option.key
                        ? 'bg-primary-selected text-primary-text'
                        : 'text-foreground hover:bg-accent'
                "
                @click="view !== option.key && emit('changeView', option.key)"
            >
                <component :is="option.icon" class="size-4" />
                <span class="hidden sm:inline">{{ $t(option.label) }}</span>
            </button>
        </div>
    </div>
</template>
