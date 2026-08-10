<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { IconLoader2, IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { start as startPostCreation } from '@/actions/App/Http/Controllers/App/PostAiCreateController';
import { Button } from '@/components/ui/button';
import { subscribePrivateChannel } from '@/composables/echo/subscribePrivateChannel';
import dateFormat from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { extractErrorMessage } from '@/lib/httpError';
import { calendar as calendarRoute } from '@/routes/app';
import { create as createPostRoute, edit as editPostRoute } from '@/routes/app/posts';

const props = defineProps<{
    creationId: string;
    channel: string;
    imageCount: number;
    format: string;
    prompt: string;
    socialAccountId: string | null;
    date: string | null;
    template: string;
    applyBrandVisuals: boolean;
}>();

const status = ref<'loading' | 'error'>('loading');
const errorMessage = ref('');
let subscribed = false;
let unmounted = false;
let generationTimeout: ReturnType<typeof setTimeout> | null = null;

const TEXT_BASELINE_SECONDS = 30;
const PER_IMAGE_SECONDS = 35;
const GENERATION_TIMEOUT_MS = 960_000;

const estimatedSeconds = computed(() => TEXT_BASELINE_SECONDS + props.imageCount * PER_IMAGE_SECONDS);

const minutesLabel = computed(() => {
    const minutes = Math.max(1, Math.ceil(estimatedSeconds.value / 60));
    const key = minutes === 1 ? 'posts.create.steps.loading_eta_minute_one' : 'posts.create.steps.loading_eta_minute_other';
    return trans(key, { count: String(minutes) });
});

const etaLabel = computed(() => trans('posts.create.steps.loading_eta', { minutes: minutesLabel.value }));

const tipKeys = [
    'posts.create.steps.loading_tip_credits',
    'posts.create.steps.loading_tip_edit',
    'posts.create.steps.loading_tip_draft',
    'posts.create.steps.loading_tip_brand',
    'posts.create.steps.loading_tip_carousel',
    'posts.create.steps.loading_tip_quality',
] as const;

const tipIndex = ref(0);
let tipTimer: ReturnType<typeof setInterval> | null = null;

const currentTip = computed(() => trans(tipKeys[tipIndex.value % tipKeys.length]));

const elapsed = ref(0);
let elapsedTimer: ReturnType<typeof setInterval> | null = null;

const elapsedLabel = computed(() => dateFormat.formatClock(elapsed.value));

const progress = computed(() => {
    const ratio = elapsed.value / estimatedSeconds.value;
    return Math.min(0.95, ratio);
});

const unsubscribe = () => {
    if (subscribed) {
        echo().leave(`private-${props.channel}`);
        subscribed = false;
    }
    if (generationTimeout) {
        clearTimeout(generationTimeout);
        generationTimeout = null;
    }
};

const httpStart = useHttp<{
    creation_id: string;
    format: string;
    social_account_id: string | null;
    image_count: number;
    prompt: string;
    date: string | null;
    template: string;
    apply_brand_visuals: boolean;
}>({
    creation_id: props.creationId,
    format: props.format,
    social_account_id: props.socialAccountId,
    image_count: props.imageCount,
    prompt: props.prompt,
    date: props.date,
    template: props.template,
    apply_brand_visuals: props.applyBrandVisuals,
});

const startGeneration = async () => {
    const confirmed = await subscribePrivateChannel(props.channel, (channel) => {
        subscribed = true;
        channel.listen('.ai.creation.completed', (e: { post_id?: string; error?: string }) => {
            unsubscribe();
            if (e.error || !e.post_id) {
                status.value = 'error';
                errorMessage.value = e.error ?? trans('posts.create.steps.preview_error');
                return;
            }
            router.visit(editPostRoute(e.post_id).url);
        });
    });

    if (unmounted) {
        unsubscribe();
        return;
    }

    if (!confirmed) {
        unsubscribe();
        status.value = 'error';
        errorMessage.value = trans('posts.create.steps.preview_error');
        return;
    }

    generationTimeout = setTimeout(() => {
        unsubscribe();
        status.value = 'error';
        errorMessage.value = trans('posts.create.steps.preview_error');
    }, GENERATION_TIMEOUT_MS);

    try {
        await httpStart.post(startPostCreation.url());

        if (httpStart.hasErrors) {
            unsubscribe();
            status.value = 'error';
            errorMessage.value = httpStart.errors.social_account_id ?? Object.values(httpStart.errors)[0] ?? trans('posts.create.steps.preview_error');
        }
    } catch (error: unknown) {
        unsubscribe();
        status.value = 'error';
        errorMessage.value = extractErrorMessage(error) ?? trans('posts.create.steps.preview_error');
    }
};

const leave = () => {
    router.visit(calendarRoute().url);
};

const createAnother = () => {
    router.visit(createPostRoute().url);
};

onMounted(() => {
    startGeneration();
    tipTimer = setInterval(() => {
        tipIndex.value = (tipIndex.value + 1) % tipKeys.length;
    }, 5000);
    elapsedTimer = setInterval(() => {
        elapsed.value += 1;
    }, 1000);
});

onBeforeUnmount(() => {
    unmounted = true;
    unsubscribe();
    if (tipTimer) clearInterval(tipTimer);
    if (elapsedTimer) clearInterval(elapsedTimer);
});
</script>

<template>
    <Head :title="$t('posts.create.steps.loading_page_title')" />

    <AppLayout>
        <div class="mx-auto flex w-full max-w-2xl flex-col items-center gap-6 px-4 py-12">
            <div class="inline-flex size-14 -rotate-2 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-2xs">
                <IconLoader2 v-if="status === 'loading'" class="size-7 animate-spin text-foreground" stroke-width="2" />
                <IconSparkles v-else class="size-7 text-foreground" stroke-width="2" />
            </div>

            <h1 class="text-center text-2xl font-bold text-foreground">
                {{ $t('posts.create.steps.loading_page_title') }}
            </h1>

            <div v-if="status === 'loading'" class="flex w-full flex-col items-center gap-4">
                <p class="text-center text-sm text-foreground/70">{{ etaLabel }}</p>

                <div class="w-full max-w-md">
                    <div class="h-2 w-full overflow-hidden rounded-full border-2 border-foreground bg-card">
                        <div
                            class="h-full bg-foreground transition-[width] duration-700 ease-out"
                            :style="{ width: `${Math.round(progress * 100)}%` }"
                        ></div>
                    </div>
                    <div class="mt-1.5 flex justify-between text-[11px] font-mono text-foreground/50">
                        <span>{{ elapsedLabel }}</span>
                        <span>{{ minutesLabel }}</span>
                    </div>
                </div>

                <div class="mt-4 flex min-h-[3rem] w-full max-w-lg items-center justify-center rounded-xl border-2 border-foreground bg-card px-5 py-3 shadow-2xs">
                    <p class="text-center text-sm text-foreground/80 transition-opacity">
                        💡 {{ currentTip }}
                    </p>
                </div>

                <div class="mt-8 flex w-full max-w-lg flex-col items-center gap-3 rounded-2xl border-2 border-foreground bg-card p-5 text-center shadow-2xs">
                    <p class="text-base font-bold text-foreground">{{ $t('posts.create.steps.loading_leave_title') }}</p>
                    <p class="text-sm text-foreground/70">{{ $t('posts.create.steps.loading_leave_body') }}</p>
                    <div class="flex flex-wrap items-center justify-center gap-2 pt-1">
                        <Button @click="createAnother">{{ $t('posts.create.steps.loading_create_another_cta') }}</Button>
                        <Button variant="outline" @click="leave">{{ $t('posts.create.steps.loading_leave_cta') }}</Button>
                    </div>
                </div>
            </div>

            <div v-else class="flex w-full max-w-lg flex-col items-center gap-4">
                <div class="w-full rounded-xl border-2 border-foreground bg-rose-50 p-4 shadow-2xs">
                    <p class="text-center text-sm font-semibold text-rose-700">
                        {{ errorMessage || $t('posts.create.steps.preview_error') }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-2">
                    <Button @click="createAnother">{{ $t('posts.create.steps.loading_create_another_cta') }}</Button>
                    <Button variant="outline" @click="leave">{{ $t('posts.create.steps.loading_leave_cta') }}</Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
