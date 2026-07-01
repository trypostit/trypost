<script setup lang="ts">
import { IconAlertTriangle, IconArrowRight, IconCircleCheck, IconLoader2, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted } from 'vue';

import { useAiGeneration } from '@/composables/useAiGeneration';

const { isGenerating, loadingCount, doneGeneration, errorGeneration, openPost, dismiss, hydrate } = useAiGeneration();

onMounted(() => hydrate());

const generatingLabel = computed(() =>
    loadingCount.value <= 1
        ? trans('posts.create.steps.bar_generating_one')
        : trans('posts.create.steps.bar_generating_other', { count: String(loadingCount.value) }),
);
</script>

<template>
    <!-- Global AI generation bar. Persists across navigation (state lives in the composable). -->
    <div
        v-if="isGenerating"
        class="flex h-8 shrink-0 items-center justify-center gap-2 border-b-2 border-foreground bg-orange-400 px-4 text-xs font-bold text-orange-950"
    >
        <IconLoader2 class="size-3.5 animate-spin" stroke-width="2.5" />
        <span>{{ generatingLabel }}</span>
    </div>

    <button
        v-else-if="doneGeneration"
        type="button"
        class="flex h-8 w-full shrink-0 cursor-pointer items-center justify-center gap-2 border-b-2 border-foreground bg-primary px-4 text-xs font-bold text-primary-foreground transition-colors hover:bg-primary/90"
        @click="openPost(doneGeneration)"
    >
        <IconCircleCheck class="size-3.5" stroke-width="2.5" />
        <span>{{ $t('posts.create.steps.bar_done') }}</span>
        <span class="inline-flex items-center gap-0.5 underline underline-offset-2">
            {{ $t('posts.create.steps.bar_done_cta') }}
            <IconArrowRight class="size-3.5" stroke-width="2.5" />
        </span>
    </button>

    <div
        v-else-if="errorGeneration"
        class="flex h-8 shrink-0 items-center justify-center gap-2 border-b-2 border-foreground bg-rose-100 px-4 text-xs font-bold text-rose-700"
    >
        <IconAlertTriangle class="size-3.5" stroke-width="2.5" />
        <span>{{ $t('posts.create.steps.bar_error') }}</span>
        <button
            type="button"
            class="cursor-pointer opacity-70 transition-opacity hover:opacity-100"
            @click="dismiss(errorGeneration.id)"
        >
            <IconX class="size-3.5" />
        </button>
    </div>
</template>
