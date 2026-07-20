<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import {
    IconAlertTriangle,
    IconArrowLeft,
    IconCheck,
    IconLoader2,
    IconSparkles,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';

import ContentRating from '@/components/ContentRating.vue';
import PageHeader from '@/components/PageHeader.vue';
import ReviewBlockCard, {
    type ReviewSlide,
} from '@/components/posts/ai/ReviewBlockCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { create as createPostRoute } from '@/routes/app/posts';
import { loading as loadingRoute } from '@/routes/app/posts/ai';
import {
    autosave as autosaveDraftRoute,
    generate as generateDraftRoute,
} from '@/routes/app/posts/ai/drafts';

interface ReviewDraft {
    id: string;
    status: string;
    format: string;
    template: string;
    is_carousel: boolean;
    image_count: number;
    supports_caption?: boolean;
    structured: Record<string, any> | null;
    error: string | null;
    channel: string;
}

const props = defineProps<{ draft: ReviewDraft }>();

const status = ref(props.draft.status);
const submitting = ref(false);

interface ReviewForm {
    caption?: string;
    content?: string;
    image_title?: string;
    image_body?: string;
    image_keywords?: string[];
    tweet_text?: string;
    slides: ReviewSlide[];
}

const form = reactive<ReviewForm>({ slides: [] });

const isCarousel = computed(() => props.draft.is_carousel);
// Formats that publish no caption (stories and the like) hide the field and
// explain why, instead of letting the user edit text that never ships.
const supportsCaption = computed(() => props.draft.supports_caption ?? true);
// Tweet-card templates carry `tweet_text` instead of the image-card fields.
const isTweet = computed(() => (props.draft.template ?? '').includes('tweet'));
const isPreparing = computed(() => status.value === 'preparing');
const isFailed = computed(() => status.value === 'failed');
const isReady = computed(
    () =>
        !isPreparing.value &&
        !isFailed.value &&
        (props.draft.structured !== null || form.slides.length > 0),
);

const skeletonCount = computed(() =>
    isCarousel.value ? Math.max(1, props.draft.image_count) : 1,
);

const singleKeywordsText = computed<string>({
    get: () => (form.image_keywords ?? []).join(', '),
    set: (value: string) => {
        form.image_keywords = value
            .split(',')
            .map((k) => k.trim())
            .filter(Boolean);
    },
});

// While true the autosave watcher ignores changes: this is hydration, not a user edit.
let hydrating = false;

const hydrateForm = (structured: Record<string, any> | null) => {
    if (!structured) {
        return;
    }

    hydrating = true;
    nextTick(() => {
        hydrating = false;
    });

    if (isCarousel.value) {
        form.caption = (structured.caption as string) ?? '';
        form.slides = ((structured.slides as ReviewSlide[]) ?? []).map((s) => ({
            ...s,
            image_keywords: [...((s.image_keywords as string[]) ?? [])],
        }));
    } else if (isTweet.value) {
        form.tweet_text = (structured.tweet_text as string) ?? '';
    } else {
        form.content = (structured.content as string) ?? '';
        form.image_title = (structured.image_title as string) ?? '';
        form.image_body = (structured.image_body as string) ?? '';
        form.image_keywords = [
            ...((structured.image_keywords as string[]) ?? []),
        ];
    }
};

let channel: unknown = null;
// Safety poll: if the broadcast is missed (the job finished between render and
// subscribe), the periodic reload reconciles instead of hanging on the skeleton.
let pollTimer: ReturnType<typeof setInterval> | null = null;

const subscribe = () => {
    channel = echo()
        .private(props.draft.channel)
        .listen('.ai.content.prepared', (e: { error?: string }) => {
            if (e.error) {
                status.value = 'failed';
                return;
            }
            // Reload the draft (now carrying the structure) without leaving the page.
            router.reload({ only: ['draft'] });
        });
};

const startWaiting = () => {
    subscribe();
    pollTimer = setInterval(() => router.reload({ only: ['draft'] }), 4000);
};

const stopWaiting = () => {
    if (channel) {
        echo().leave(`private-${props.draft.channel}`);
        channel = null;
    }
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

onMounted(() => {
    status.value = props.draft.status;
    if (props.draft.structured) {
        hydrateForm(props.draft.structured);
    }
    if (props.draft.status === 'preparing') {
        startWaiting();
    }
});

onBeforeUnmount(stopWaiting);

// After the Inertia reload (prepared event), rehydrate with the finished text.
watch(
    () => props.draft,
    (draft) => {
        status.value = draft.status;
        if (draft.status !== 'preparing') {
            if (draft.structured) {
                hydrateForm(draft.structured);
            }
            stopWaiting();
        }
    },
    { deep: true },
);

// Shallow (non-recursive) payload type — avoids FormDataType's "excessively deep" error.
type StructuredField = string | string[] | undefined;
type StructuredSlide = Record<string, StructuredField>;
type StructuredPayload = Record<string, StructuredField | StructuredSlide[]>;

const buildStructured = (): StructuredPayload => {
    if (isCarousel.value) {
        return {
            caption: form.caption,
            slides: form.slides as StructuredSlide[],
        };
    }
    if (isTweet.value) {
        return { tweet_text: form.tweet_text };
    }

    return {
        content: form.content,
        image_title: form.image_title,
        image_body: form.image_body,
        image_keywords: form.image_keywords,
    };
};

const httpGenerate = useHttp<{ structured: StructuredPayload }>({
    structured: {},
});

const generate = async () => {
    if (submitting.value) {
        return;
    }
    submitting.value = true;
    httpGenerate.structured = buildStructured();

    try {
        const data = (await httpGenerate.post(
            generateDraftRoute.url({ draft: props.draft.id }),
        )) as { creation_id: string };

        router.visit(
            loadingRoute(
                { creationId: data.creation_id },
                {
                    query: {
                        images: String(props.draft.image_count),
                        format: props.draft.format,
                    },
                },
            ).url,
        );
    } catch (err: any) {
        submitting.value = false;
        toast.error(
            err?.response?.data?.message ??
                trans('posts.create.steps.preview_error'),
        );
    }
};

const goBack = () => {
    router.visit(createPostRoute().url);
};

// Slide reordering. `dragEnabled` only turns on while the grip is held, so it
// never interferes with selecting text inside the inputs.
const dragEnabled = ref(false);
const draggingIndex = ref<number | null>(null);
const dragOverIndex = ref<number | null>(null);

const onDragStart = (i: number) => {
    draggingIndex.value = i;
};

const onDragOver = (i: number) => {
    dragOverIndex.value = i;
};

const onDrop = (i: number) => {
    const from = draggingIndex.value;
    if (from === null || from === i) {
        return;
    }
    const slides = [...form.slides];
    const [moved] = slides.splice(from, 1);
    slides.splice(i, 0, moved);
    form.slides = slides;
};

const onDragEnd = () => {
    draggingIndex.value = null;
    dragOverIndex.value = null;
    dragEnabled.value = false;
};

// Autosave: every edit persists the draft (debounced) with a status indicator.
const saveState = ref<'idle' | 'saving' | 'saved'>('idle');
const httpAutosave = useHttp<{ structured: StructuredPayload }>({
    structured: {},
});
let saveTimer: ReturnType<typeof setTimeout> | null = null;

const scheduleAutosave = () => {
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
    saveState.value = 'saving';
    saveTimer = setTimeout(async () => {
        httpAutosave.structured = buildStructured();
        try {
            await httpAutosave.post(
                autosaveDraftRoute.url({ draft: props.draft.id }),
            );
            saveState.value = 'saved';
        } catch {
            saveState.value = 'idle';
        }
    }, 1200);
};

watch(
    form,
    () => {
        if (hydrating || !isReady.value || submitting.value) {
            return;
        }
        scheduleAutosave();
    },
    { deep: true },
);

onBeforeUnmount(() => {
    if (saveTimer) {
        clearTimeout(saveTimer);
    }
});
</script>

<template>
    <Head :title="$t('posts.ai_review.page_title')" />

    <AppLayout>
        <div
            class="mx-auto flex w-full flex-col gap-6 p-4"
            :class="isCarousel ? 'max-w-5xl' : 'max-w-2xl'"
        >
            <PageHeader
                :title="$t('posts.ai_review.title')"
                :description="$t('posts.ai_review.description')"
            />

            <!-- Preparing: skeleton on the same grid while the text is generated. -->
            <div v-if="isPreparing" class="space-y-4">
                <div
                    class="inline-flex items-center gap-2 rounded-full border-2 border-foreground bg-card px-3 py-1.5 text-xs font-bold text-foreground shadow-2xs"
                >
                    <IconLoader2 class="size-4 animate-spin" />
                    {{ $t('posts.ai_review.preparing') }}
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="n in skeletonCount"
                        :key="n"
                        class="animate-pulse space-y-2 rounded-xl border-2 border-foreground/30 bg-card p-2.5"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-1.5">
                                <div class="h-4 w-2 rounded bg-muted"></div>
                                <div class="size-6 rounded-lg bg-muted"></div>
                            </div>
                            <div class="h-4 w-16 rounded-full bg-muted"></div>
                        </div>

                        <div class="h-64 w-full rounded-md bg-muted"></div>

                        <div
                            class="space-y-1 border-t-2 border-foreground/10 pt-2"
                        >
                            <div class="h-2.5 w-24 rounded bg-muted"></div>
                            <div class="h-8 w-full rounded-md bg-muted"></div>
                        </div>
                    </div>
                </div>

                <div v-if="isCarousel" class="animate-pulse space-y-1.5">
                    <div class="h-4 w-24 rounded bg-muted"></div>
                    <div
                        class="h-40 w-full rounded-md border-2 border-foreground/30 bg-muted"
                    ></div>
                </div>
            </div>

            <!-- The text generation failed. -->
            <div
                v-else-if="isFailed"
                class="flex flex-col items-center gap-4 rounded-2xl border-2 border-foreground bg-destructive/10 p-6 text-center shadow-2xs"
            >
                <span
                    class="flex size-12 items-center justify-center rounded-2xl border-2 border-foreground bg-destructive/20"
                >
                    <IconAlertTriangle class="size-6 text-destructive" />
                </span>
                <p class="text-sm font-semibold text-destructive">
                    {{ $t('posts.ai_review.failed') }}
                </p>
                <Button variant="outline" @click="goBack">{{
                    $t('posts.ai_review.back')
                }}</Button>
            </div>

            <!-- Review: editable blocks. -->
            <template v-else-if="isReady">
                <!-- Carousel: grid of cards, draggable to reorder. -->
                <template v-if="isCarousel">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div
                            v-for="(slide, i) in form.slides"
                            :key="i"
                            :draggable="dragEnabled"
                            class="rounded-xl transition"
                            :class="[
                                dragOverIndex === i && draggingIndex !== i
                                    ? 'ring-2 ring-primary ring-offset-2'
                                    : '',
                                draggingIndex === i ? 'opacity-40' : '',
                            ]"
                            @dragstart="onDragStart(i)"
                            @dragover.prevent="onDragOver(i)"
                            @drop="onDrop(i)"
                            @dragend="onDragEnd"
                        >
                            <ReviewBlockCard
                                :slide="slide"
                                :index="i"
                                :is-tweet="isTweet"
                                @grab="dragEnabled = true"
                                @release="dragEnabled = false"
                            />
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label class="text-sm font-bold">{{
                            $t('posts.ai_review.caption')
                        }}</Label>
                        <Textarea
                            v-model="form.caption"
                            class="min-h-40"
                            style="resize: vertical; field-sizing: fixed"
                            :placeholder="
                                $t('posts.ai_review.caption_placeholder')
                            "
                        />
                    </div>
                </template>

                <!-- Single post: tweet card. -->
                <div
                    v-else-if="isTweet"
                    class="space-y-3 rounded-2xl border-2 border-foreground bg-card p-4 shadow-2xs"
                >
                    <div class="space-y-1">
                        <Label class="text-xs font-bold text-foreground/70">{{
                            $t('posts.ai_review.field_tweet_text')
                        }}</Label>
                        <Textarea
                            v-model="form.tweet_text"
                            class="min-h-64"
                            style="resize: vertical; field-sizing: fixed"
                        />
                    </div>
                </div>

                <!-- Single post: image card. -->
                <div
                    v-else
                    class="space-y-3 rounded-2xl border-2 border-foreground bg-card p-4 shadow-2xs"
                >
                    <div v-if="supportsCaption" class="space-y-1">
                        <Label class="text-xs font-bold text-foreground/70">{{
                            $t('posts.ai_review.caption')
                        }}</Label>
                        <Textarea
                            v-model="form.content"
                            class="min-h-40"
                            style="resize: vertical; field-sizing: fixed"
                        />
                    </div>
                    <p
                        v-else
                        class="rounded-lg border-2 border-foreground/15 bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
                    >
                        {{ $t('posts.ai_review.no_caption_for_format') }}
                    </p>
                    <div class="space-y-1">
                        <Label class="text-xs font-bold text-foreground/70">{{
                            $t('posts.ai_review.field_image_title')
                        }}</Label>
                        <Input v-model="form.image_title" />
                    </div>
                    <div class="space-y-1">
                        <Label class="text-xs font-bold text-foreground/70">{{
                            $t('posts.ai_review.field_image_body')
                        }}</Label>
                        <Textarea
                            v-model="form.image_body"
                            class="min-h-40"
                            style="resize: vertical; field-sizing: fixed"
                        />
                    </div>
                    <div class="space-y-1">
                        <Label class="text-xs font-bold text-foreground/70">{{
                            $t('posts.ai_review.field_image_keywords')
                        }}</Label>
                        <p
                            class="text-[11px] leading-tight text-muted-foreground"
                        >
                            {{
                                $t('posts.ai_review.field_image_keywords_hint')
                            }}
                        </p>
                        <Input
                            v-model="singleKeywordsText"
                            :placeholder="
                                $t(
                                    'posts.ai_review.field_image_keywords_placeholder',
                                )
                            "
                        />
                    </div>
                </div>

                <!-- Quality signal on the generated draft. Never blocks. -->
                <div class="flex justify-center py-2">
                    <ContentRating
                        rateable-type="aiPostDraft"
                        :rateable-id="draft.id"
                    />
                </div>

                <!-- Action bar: sticky inside the column, so it respects the sidebar. -->
                <div
                    class="sticky bottom-0 z-20 -mx-4 flex items-center gap-3 border-t-2 border-foreground bg-background/95 px-4 py-4 backdrop-blur"
                >
                    <Button
                        variant="outline"
                        :disabled="submitting"
                        @click="goBack"
                    >
                        <IconArrowLeft class="size-4" />
                        {{ $t('posts.ai_review.back') }}
                    </Button>

                    <span
                        class="flex flex-1 items-center justify-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <template v-if="saveState === 'saving'">
                            <IconLoader2 class="size-3.5 animate-spin" />
                            {{ $t('posts.ai_review.saving') }}
                        </template>
                        <template v-else-if="saveState === 'saved'">
                            <IconCheck class="size-3.5" />
                            {{ $t('posts.ai_review.saved') }}
                        </template>
                    </span>

                    <Button :disabled="submitting" @click="generate">
                        <IconLoader2
                            v-if="submitting"
                            class="size-4 animate-spin"
                        />
                        <IconSparkles v-else class="size-4" />
                        {{ $t('posts.ai_review.generate') }}
                    </Button>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
