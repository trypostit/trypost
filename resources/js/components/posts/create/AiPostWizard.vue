<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCheck,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';


import { start as startRoute } from '@/actions/App/Http/Controllers/App/PostAiCreateController';
import ContentStylePicker from '@/components/ai/ContentStylePicker.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { loading as loadingRoute } from '@/routes/app/posts/ai';
import { ContentType, type ContentTypeValue } from '@/types/content-type';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    avatar_url: string | null;
}

interface AiTemplate {
    key: string;
    name: string;
    description: string;
    preview: string;
    needs_account: boolean;
    supported_formats: string[];
    applies_brand_visuals: boolean;
}

interface Props {
    socialAccounts: SocialAccount[];
    templates: AiTemplate[];
    /** ISO date (YYYY-MM-DD) carried over from the calendar's per-day "+" button. */
    date?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    date: null,
});

const emit = defineEmits<{
    /** Parent mirrors this in the PageHeader for context. */
    'update:stepHeader': [{ title: string; description: string }];
    /** Back button asks parent to leave the AI flow. */
    cancel: [];
}>();

const CAROUSEL_FORMAT = 'instagram_carousel' as const;
type AiFormat = ContentTypeValue | typeof CAROUSEL_FORMAT;

// Selections
const selectedFormat = ref<AiFormat | null>(null);
const selectedStyle = ref<string>('image_card');
const selectedAccountId = ref<string | null>(null);
const includeImages = ref(true);
const imageCount = ref(2);
const promptText = ref('');
// true = images use the workspace brand palette; false = the AI picks colors freely.
const useBrandColors = ref(true);
const PROMPT_MIN = 3;
const PROMPT_MAX = 2000;

const submitting = ref(false);

const httpStart = useHttp<{
    format: string | null;
    social_account_id: string | null;
    image_count: number;
    prompt: string;
    date: string | null;
    template: string;
    apply_brand_visuals: boolean;
}>({ format: null, social_account_id: null, image_count: 0, prompt: '', date: null, template: 'image_card', apply_brand_visuals: true });

const AI_FORMATS: Array<{ value: AiFormat; platforms: string[] }> = [
    { value: ContentType.InstagramFeed, platforms: ['instagram', 'instagram-facebook'] },
    { value: CAROUSEL_FORMAT, platforms: ['instagram', 'instagram-facebook'] },
    { value: ContentType.InstagramStory, platforms: ['instagram', 'instagram-facebook'] },
    { value: ContentType.LinkedInPost, platforms: ['linkedin'] },
    { value: ContentType.LinkedInPagePost, platforms: ['linkedin-page'] },
    { value: ContentType.XPost, platforms: ['x'] },
    { value: ContentType.BlueskyPost, platforms: ['bluesky'] },
    { value: ContentType.ThreadsPost, platforms: ['threads'] },
    { value: ContentType.MastodonPost, platforms: ['mastodon'] },
    { value: ContentType.FacebookPost, platforms: ['facebook'] },
    { value: ContentType.PinterestPin, platforms: ['pinterest'] },
];

/** Templates with no format restriction — pure visual styles (image_card, tweet_card). */
const styleTemplates = computed(() => props.templates.filter((t) => t.supported_formats.length === 0));

/** The template whose supported_formats includes the currently selected format (e.g. carousel). */
const formatBoundTemplate = computed(() =>
    selectedFormat.value
        ? props.templates.find((t) => t.supported_formats.includes(selectedFormat.value as string)) ?? null
        : null,
);

/** The template key that will be sent to the backend. */
const resolvedTemplate = computed(() =>
    formatBoundTemplate.value ? formatBoundTemplate.value.key : selectedStyle.value,
);

const resolvedTemplateRecord = computed(() =>
    props.templates.find((t) => t.key === resolvedTemplate.value) ?? null,
);

const connectedPlatforms = computed(() => {
    const platforms = new Set<string>();
    for (const account of props.socialAccounts) {
        platforms.add(account.platform);
    }
    return Array.from(platforms);
});

const availableFormats = computed(() => AI_FORMATS);

const isFormatConnected = (format: typeof AI_FORMATS[number]): boolean =>
    format.platforms.some((p) => connectedPlatforms.value.includes(p));

const accountsForFormat = computed(() => {
    if (!selectedFormat.value) return [];
    const format = AI_FORMATS.find((f) => f.value === selectedFormat.value);
    if (!format) return [];
    return props.socialAccounts.filter((a) => format.platforms.includes(a.platform));
});

const isCarousel = computed(() => selectedFormat.value === CAROUSEL_FORMAT);
const requiresImage = computed(() =>
    selectedFormat.value === ContentType.FacebookPost ||
    selectedFormat.value === ContentType.PinterestPin ||
    selectedFormat.value === ContentType.InstagramStory,
);
const supportsOptionalImages = computed(() =>
    selectedFormat.value === ContentType.InstagramFeed ||
    selectedFormat.value === ContentType.LinkedInPost ||
    selectedFormat.value === ContentType.LinkedInPagePost ||
    selectedFormat.value === ContentType.XPost ||
    selectedFormat.value === ContentType.BlueskyPost ||
    selectedFormat.value === ContentType.ThreadsPost ||
    selectedFormat.value === ContentType.MastodonPost,
);
const maxOptionalImages = computed(() =>
    selectedFormat.value === ContentType.InstagramFeed ? 1 : 4,
);
const showsAccountPicker = computed(() => accountsForFormat.value.length > 1);

const templateNeedsAccount = computed(() => resolvedTemplateRecord.value?.needs_account ?? false);

const submittedImageCount = computed(() => {
    if (isCarousel.value) return imageCount.value;
    if (requiresImage.value) return 1;
    if (supportsOptionalImages.value && includeImages.value) return imageCount.value;
    return 0;
});

const promptLength = computed(() => [...promptText.value.trim()].length);

const canSubmit = computed(() =>
    selectedFormat.value !== null &&
    selectedAccountId.value !== null &&
    promptLength.value >= PROMPT_MIN &&
    promptLength.value <= PROMPT_MAX,
);

// Auto-pick the only account when format has exactly one match.
watch(accountsForFormat, (accounts) => {
    if (accounts.length === 1) {
        selectedAccountId.value = accounts[0].id;
    } else if (accounts.length === 0) {
        selectedAccountId.value = null;
    } else if (accounts.length > 1 && !accounts.some((a) => a.id === selectedAccountId.value)) {
        selectedAccountId.value = null;
    }
});

const selectFormat = (format: AiFormat) => {
    selectedFormat.value = format;
    if (format === CAROUSEL_FORMAT) {
        imageCount.value = 5;
    } else if (format === ContentType.InstagramFeed) {
        imageCount.value = 1;
        includeImages.value = true;
    } else {
        imageCount.value = 2;
        includeImages.value = true;
    }
};

emit('update:stepHeader', {
    title: trans('posts.create.ai_title'),
    description: trans('posts.create.ai_configure_description'),
});

const goBack = () => {
    emit('cancel');
};

const startGeneration = async () => {
    if (!canSubmit.value || submitting.value) return;

    submitting.value = true;

    httpStart.format = selectedFormat.value;
    httpStart.social_account_id = selectedAccountId.value;
    httpStart.image_count = submittedImageCount.value;
    httpStart.prompt = promptText.value.trim();
    httpStart.date = props.date;
    httpStart.template = resolvedTemplate.value;
    httpStart.apply_brand_visuals = useBrandColors.value;

    try {
        const data = await httpStart.post(startRoute.url()) as { creation_id: string; channel: string };

        router.visit(loadingRoute(
            { creationId: data.creation_id },
            {
                query: {
                    images: String(submittedImageCount.value),
                    format: selectedFormat.value ?? '',
                    prompt: promptText.value.trim(),
                },
            },
        ).url);
    } catch (err: any) {
        toast.error(err?.response?.data?.message ?? trans('posts.create.steps.preview_error'));
        submitting.value = false;
    }
};

</script>

<template>
    <div class="space-y-6">
        <!-- Back button -->
        <button
            type="button"
            class="group inline-flex cursor-pointer items-center gap-1.5 text-sm font-semibold text-foreground/70 transition-colors hover:text-foreground"
            @click="goBack"
        >
            <span class="inline-flex size-7 items-center justify-center rounded-md border-2 border-foreground bg-card shadow-2xs transition-transform group-hover:-translate-x-0.5">
                <IconArrowLeft class="size-3.5 text-foreground" stroke-width="2.5" />
            </span>
            {{ $t('posts.create.steps.back') }}
        </button>

        <!-- Format -->
        <div class="space-y-2">
            <Label class="text-sm font-bold">{{ $t('posts.create.steps.format_title') }}</Label>
            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    v-for="format in availableFormats"
                    :key="format.value"
                    type="button"
                    class="flex cursor-pointer items-center gap-3 rounded-xl border-2 border-foreground bg-card p-3.5 text-left text-sm shadow-2xs transition-all hover:bg-foreground/5 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-card"
                    :class="{ '!bg-violet-100 shadow-md': selectedFormat === format.value }"
                    :disabled="!isFormatConnected(format)"
                    :title="!isFormatConnected(format) ? $t('posts.create.steps.connect_first') : ''"
                    @click="selectFormat(format.value)"
                >
                    <span class="inline-flex size-7 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                        <img
                            :src="getPlatformLogo(format.platforms[0])"
                            :alt="format.platforms[0]"
                            class="size-full object-cover"
                        />
                    </span>
                    <span class="flex-1 font-semibold text-foreground">{{ $t(`posts.create.steps.format.${format.value}`) }}</span>
                    <IconCheck v-if="selectedFormat === format.value" class="size-4 text-foreground" stroke-width="3" />
                </button>
            </div>
        </div>

        <!-- Visual style — shown only for single-image formats (not carousel) -->
        <div v-if="selectedFormat && !formatBoundTemplate" class="space-y-2">
            <Label class="text-sm font-bold">{{ $t('posts.create.steps.template_picker_title') }}</Label>
            <ContentStylePicker v-model="selectedStyle" :styles="styleTemplates" />
        </div>

        <!-- Account (when template needs_account OR there's a choice to make) -->
        <div v-if="selectedFormat && (templateNeedsAccount || showsAccountPicker)" class="space-y-2">
            <Label class="text-sm font-bold">{{ $t('posts.create.steps.account_title') }}</Label>
            <p v-if="templateNeedsAccount && accountsForFormat.length === 0" class="text-sm text-foreground/60">
                {{ $t('posts.create.steps.no_account_for_template') }}
            </p>
            <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <button
                    v-for="account in accountsForFormat"
                    :key="account.id"
                    type="button"
                    class="relative flex cursor-pointer items-center gap-2 rounded-xl border-2 border-foreground bg-card p-2.5 text-left text-sm shadow-2xs transition-all hover:bg-foreground/5"
                    :class="{ '!bg-violet-100 shadow-md': selectedAccountId === account.id }"
                    @click="selectedAccountId = account.id"
                >
                    <span class="inline-flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                        <img
                            v-if="account.avatar_url"
                            :src="account.avatar_url"
                            :alt="account.display_name"
                            class="size-full object-cover"
                        />
                        <img v-else :src="getPlatformLogo(account.platform)" :alt="account.platform" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-bold leading-tight text-foreground">{{ account.display_name }}</p>
                        <p v-if="account.username" class="truncate text-xs font-medium text-foreground/60">@{{ account.username }}</p>
                    </div>
                    <IconCheck v-if="selectedAccountId === account.id" class="absolute right-2 top-2 size-3.5 text-foreground" stroke-width="3" />
                </button>
            </div>
        </div>

        <!-- Media — inline, only when format actually has options -->
        <div v-if="selectedFormat && isCarousel" class="space-y-2">
            <Label class="text-sm font-bold">{{ $t('posts.create.steps.media_carousel') }}</Label>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="n in [2, 3, 4, 5, 6, 7, 8, 9, 10]"
                    :key="n"
                    type="button"
                    size="icon"
                    :variant="imageCount === n ? 'default' : 'outline'"
                    @click="imageCount = n"
                >
                    {{ n }}
                </Button>
            </div>
        </div>

        <div v-if="selectedFormat && supportsOptionalImages" class="space-y-2">
            <Label class="text-sm font-bold">{{ $t('posts.create.steps.media_optional_label') }}</Label>
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    :variant="!includeImages ? 'default' : 'outline'"
                    @click="includeImages = false"
                >
                    {{ $t('posts.create.steps.media_none') }}
                </Button>
                <Button
                    v-for="n in maxOptionalImages"
                    :key="n"
                    type="button"
                    size="icon"
                    :variant="includeImages && imageCount === n ? 'default' : 'outline'"
                    @click="includeImages = true; imageCount = n"
                >
                    {{ n }}
                </Button>
            </div>
        </div>

        <!-- Brand colors: apply the workspace palette or let the AI decide. Only
             for image templates that honor it (tweet cards are always branded). -->
        <div
            v-if="selectedFormat && submittedImageCount > 0 && resolvedTemplateRecord?.applies_brand_visuals"
            class="flex items-center justify-between gap-4 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs"
        >
            <div class="space-y-0.5">
                <Label for="apply-brand-visuals" class="text-sm font-bold">{{ $t('posts.create.steps.brand_colors_label') }}</Label>
                <p class="text-sm text-foreground/70">{{ $t('posts.create.steps.brand_colors_description') }}</p>
            </div>
            <Switch id="apply-brand-visuals" v-model="useBrandColors" />
        </div>

        <!-- Prompt -->
        <div v-if="selectedFormat" class="space-y-2">
            <Label for="ai-prompt" class="text-sm font-bold">{{ $t('posts.create.steps.prompt_label') }}</Label>
            <Textarea
                id="ai-prompt"
                v-model="promptText"
                :placeholder="$t('posts.create.steps.prompt_placeholder')"
                class="min-h-[140px] resize-none"
            />
            <p
                data-testid="ai-prompt-counter"
                aria-live="polite"
                class="text-right text-xs tabular-nums"
                :class="promptLength > PROMPT_MAX ? 'font-semibold text-destructive' : 'text-muted-foreground'"
            >
                {{ promptLength }}/{{ PROMPT_MAX }}
            </p>
        </div>

        <!-- Generate -->
        <div v-if="selectedFormat" class="flex justify-end pt-1">
            <Button :disabled="!canSubmit || submitting" @click="startGeneration">
                {{ $t('posts.ai.generate.start') }}
            </Button>
        </div>
    </div>
</template>
