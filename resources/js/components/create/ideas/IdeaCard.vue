<script setup lang="ts">
import { IconPlayerPlayFilled } from '@tabler/icons-vue';
import { computed, inject, ref } from 'vue';

import IdeaCardMenu from '@/components/create/ideas/IdeaCardMenu.vue';
import LabelBadge from '@/components/labels/LabelBadge.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { isImage, isVideo } from '@/lib/mediaType';
import {
    ideaCardActionsKey,
    type IdeaCard,
    type IdeaStage,
    type IdeaLabel,
    type IdeasView,
} from '@/types/idea';

const props = defineProps<{
    card: IdeaCard;
    view: IdeasView;
    stages: IdeaStage[];
    labels: Map<string, IdeaLabel>;
    selected: boolean;
    selecting: boolean;
    preview?: boolean;
}>();

const actions = inject(ideaCardActionsKey)!;

const menuOpen = ref(false);

const cardLabels = computed(() =>
    props.card.label_ids
        .map((id) => props.labels.get(id))
        .filter((label): label is IdeaLabel => label !== undefined),
);

const onClick = (): void => {
    if (props.preview) {
        return;
    }

    if (props.selecting) {
        actions.toggle(props.card);

        return;
    }

    actions.open(props.card);
};
</script>

<template>
    <article
        class="group/card relative flex w-full cursor-pointer flex-col gap-2 overflow-hidden border bg-card p-4 text-start transition-[border-color,opacity] duration-150"
        :class="[
            view === 'gallery' ? 'rounded-md' : 'rounded-lg',
            selected ? 'border-primary-strong' : 'border-border',
        ]"
        :data-testid="preview ? 'idea-card-placeholder-preview' : `idea-card-${card.id}`"
        :data-idea-id="preview ? undefined : card.id"
        :role="preview ? undefined : 'button'"
        :aria-pressed="selecting && !preview ? selected : undefined"
        :tabindex="preview ? undefined : 0"
        @click="onClick"
        @keydown.enter.self="onClick"
        @keydown.space.self.prevent="onClick"
    >
        <div
            v-if="card.cover && (isImage(card.cover) || isVideo(card.cover))"
            class="relative -mx-4 -mt-4 overflow-hidden bg-muted"
            :class="view === 'board' ? 'h-[95px]' : ''"
        >
            <img
                v-if="isImage(card.cover)"
                :src="card.cover.url"
                :alt="card.cover.meta?.alt_text ?? ''"
                class="w-full object-cover"
                :class="view === 'board' ? 'h-full' : 'h-auto'"
                loading="lazy"
                draggable="false"
            />
            <template v-else>
                <video
                    :src="card.cover.url"
                    class="w-full object-cover"
                    :class="view === 'board' ? 'h-full' : 'h-auto'"
                    muted
                    playsinline
                    preload="metadata"
                />
                <IconPlayerPlayFilled
                    aria-hidden="true"
                    class="absolute top-1/2 left-1/2 size-7 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-1.5 text-white"
                />
            </template>
        </div>

        <h3
            v-if="card.title"
            class="line-clamp-2 pe-6 text-sm leading-5 font-emphasis break-words text-foreground"
            :class="{ 'ps-6': selecting && !card.cover }"
        >
            {{ card.title }}
        </h3>
        <p
            v-if="card.excerpt"
            class="line-clamp-3 text-sm leading-5 break-words whitespace-pre-line text-muted-foreground"
            :class="{
                'pe-6': !card.title,
                'ps-6': !card.title && selecting && !card.cover,
            }"
        >
            {{ card.excerpt }}
        </p>

        <div v-if="cardLabels.length" class="flex flex-wrap gap-1">
            <LabelBadge
                v-for="label in cardLabels"
                :key="label.id"
                :label="label"
            />
        </div>

        <div
            v-if="selecting && !preview"
            class="absolute top-2 left-2 flex rounded-sm bg-card"
            @click.stop
        >
            <Checkbox
                :model-value="selected"
                :aria-label="card.title || $t('create.ideas.untitled')"
                :data-testid="`idea-card-checkbox-${card.id}`"
                @update:model-value="actions.toggle(card)"
            />
        </div>

        <div
            v-if="!preview"
            class="absolute top-2 right-2 opacity-0 transition-opacity duration-150 group-hover/card:opacity-100 focus-within:opacity-100"
            :class="{ 'opacity-100': menuOpen }"
            @click.stop
        >
            <IdeaCardMenu
                v-model:open="menuOpen"
                :card="card"
                :stages="stages"
                @select="actions.select(card)"
                @move="(stageId) => actions.move(card, stageId)"
                @duplicate="actions.duplicate(card)"
                @delete="actions.remove(card)"
            />
        </div>
    </article>
</template>
