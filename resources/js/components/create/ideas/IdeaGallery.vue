<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';

import IdeaCard from '@/components/create/ideas/IdeaCard.vue';
import type { IdeaCard as IdeaCardData, IdeaStage, IdeaLabel } from '@/types/idea';

defineProps<{
    cards: IdeaCardData[];
    stages: IdeaStage[];
    labels: Map<string, IdeaLabel>;
    selectedIds: Set<string>;
}>();
</script>

<template>
    <div
        class="min-h-0 min-w-0 flex-1 overflow-auto overscroll-contain"
        data-testid="ideas-gallery"
    >
        <InfiniteScroll data="ideas" items-element="#ideas-gallery-body" preserve-url>
            <div
                id="ideas-gallery-body"
                class="flex flex-wrap items-start gap-4 px-4 pt-6 pb-12 md:px-8"
            >
                <div v-for="card in cards" :key="card.id" class="w-full sm:w-62">
                    <IdeaCard
                        :card="card"
                        view="gallery"
                        :stages="stages"
                        :labels="labels"
                        :selected="selectedIds.has(card.id)"
                        :selecting="selectedIds.size > 0"
                    />
                </div>
            </div>

            <template #next="{ loading }">
                <p
                    v-if="loading"
                    class="py-5 text-center text-sm text-muted-foreground"
                    role="status"
                >
                    {{ $t('common.loading_more') }}
                </p>
            </template>
        </InfiniteScroll>
    </div>
</template>
