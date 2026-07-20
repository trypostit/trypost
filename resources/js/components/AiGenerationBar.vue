<script setup lang="ts">
import { IconAlertTriangle, IconArrowRight, IconCircleCheck, IconLoader2, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, watch } from 'vue';

import { useAiGeneration } from '@/composables/useAiGeneration';

const { isGenerating, loadingCount, doneGenerations, errorGenerations, openNextDone, dismissErrors, markSeen, hydrate } =
    useAiGeneration();

onMounted(() => hydrate());

// Every generation that reaches a terminal state is surfaced as soon as it does,
// even while others are still running — and only then does its TTL start.
watch([doneGenerations, errorGenerations], ([done, failed]) => [...done, ...failed].forEach((gen) => markSeen(gen.id)), {
    immediate: true,
});

const generatingLabel = computed(() =>
    loadingCount.value <= 1
        ? trans('posts.create.steps.bar_generating_one')
        : trans('posts.create.steps.bar_generating_other', { count: String(loadingCount.value) }),
);

const doneLabel = computed(() =>
    doneGenerations.value.length <= 1
        ? trans('posts.create.steps.bar_done')
        : trans('posts.create.steps.bar_done_other', { count: String(doneGenerations.value.length) }),
);

const errorLabel = computed(() =>
    errorGenerations.value.length <= 1
        ? trans('posts.create.steps.bar_error')
        : trans('posts.create.steps.bar_error_other', { count: String(errorGenerations.value.length) }),
);
</script>

<template>
    <!--
        Global AI generation bar. Persists across navigation (state lives in the
        composable). The strips COEXIST instead of competing for a single slot: a
        post that is ready shows up right away even while another is still
        generating, and a failure is never hidden behind a success.
    -->
    <div class="contents">
        <div
            v-if="errorGenerations.length"
            class="flex h-8 shrink-0 items-center justify-center gap-2 border-b-2 border-foreground bg-rose-100 px-4 text-xs font-bold text-rose-700"
        >
            <IconAlertTriangle class="size-3.5" stroke-width="2.5" />
            <span>{{ errorLabel }}</span>
            <button
                type="button"
                class="cursor-pointer opacity-70 transition-opacity hover:opacity-100"
                @click="dismissErrors()"
            >
                <IconX class="size-3.5" />
            </button>
        </div>

        <button
            v-if="doneGenerations.length"
            type="button"
            class="flex h-8 w-full shrink-0 cursor-pointer items-center justify-center gap-2 border-b-2 border-foreground bg-primary px-4 text-xs font-bold text-primary-foreground transition-colors hover:bg-primary/90"
            @click="openNextDone()"
        >
            <IconCircleCheck class="size-3.5" stroke-width="2.5" />
            <span>{{ doneLabel }}</span>
            <span class="inline-flex items-center gap-0.5 underline underline-offset-2">
                {{ $t('posts.create.steps.bar_done_cta') }}
                <IconArrowRight class="size-3.5" stroke-width="2.5" />
            </span>
        </button>

        <div
            v-if="isGenerating"
            class="flex h-8 shrink-0 items-center justify-center gap-2 border-b-2 border-foreground bg-orange-400 px-4 text-xs font-bold text-orange-950"
        >
            <IconLoader2 class="size-3.5 animate-spin" stroke-width="2.5" />
            <span>{{ generatingLabel }}</span>
        </div>
    </div>
</template>
