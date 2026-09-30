<script setup lang="ts">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Avatar } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { toNullableText } from '@/lib/utils';
import {
    getYouTubeDescriptionIssue,
    YOUTUBE_DESCRIPTION_MAX_BYTES,
    youtubeDescriptionBytes,
} from '@/lib/youtubeDescription';
import type { ChannelAccount } from '@/types/channel';
import { Platform } from '@/types/platform';

interface Props {
    socialAccount: ChannelAccount | null;
    platformIndex: number;
    meta: Record<string, unknown>;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
});
const emit = defineEmits<{
    'update:meta': [value: Record<string, unknown>];
}>();

const open = ref(false);
const errors = usePageErrors();
const descriptionId = computed(() => `youtube-description-${props.platformIndex}`);

const description = computed({
    get: () => toNullableText(props.meta.description) ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            description: toNullableText(value),
        }),
});
const usedBytes = computed(() => youtubeDescriptionBytes(description.value));
const descriptionError = computed(() => {
    const issue = getYouTubeDescriptionIssue(props.meta.description);

    return issue
        ? trans(issue)
        : errors.value[`platforms.${props.platformIndex}.meta.description`];
});

const FIRST_COMMENT_MAX = 2200;
const firstCommentId = computed(
    () => `youtube-first-comment-${props.platformIndex}`,
);
const firstComment = computed({
    get: () => toNullableText(props.meta.first_comment) ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            first_comment: toNullableText(value),
        }),
});
const firstCommentError = computed(
    () => errors.value[`platforms.${props.platformIndex}.meta.first_comment`],
);

const TITLE_MAX = 100;
const title = computed({
    get: () => toNullableText(props.meta.title) ?? '',
    set: (value: string) =>
        emit('update:meta', { ...props.meta, title: toNullableText(value) }),
});

const tags = computed({
    get: () => ((props.meta.tags as string[] | undefined) ?? []).join(', '),
    set: (value: string) => {
        const parsed = value
            .split(',')
            .map((t) => t.trim())
            .filter(Boolean);
        emit('update:meta', {
            ...props.meta,
            tags: parsed.length ? parsed : null,
        });
    },
});

const categoryId = computed({
    get: () => toNullableText(props.meta.category_id) ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            category_id: toNullableText(value),
        }),
});

const defaultLanguage = computed({
    get: () => toNullableText(props.meta.default_language) ?? '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            default_language: toNullableText(value),
        }),
});

const recording = computed(
    () =>
        (props.meta.recording_location as Record<string, unknown> | undefined) ??
        null,
);

const setRecording = (patch: Record<string, unknown>) => {
    const next = { ...(recording.value ?? {}), ...patch };
    const empty =
        !String(next.lat ?? '').length &&
        !String(next.lng ?? '').length &&
        !String(next.description ?? '').trim();
    emit('update:meta', {
        ...props.meta,
        recording_location: empty ? null : next,
    });
};

const recLat = computed({
    get: () => String(recording.value?.lat ?? ''),
    set: (value: string) =>
        setRecording({ lat: value === '' ? null : Number(value) }),
});
const recLng = computed({
    get: () => String(recording.value?.lng ?? ''),
    set: (value: string) =>
        setRecording({ lng: value === '' ? null : Number(value) }),
});
const recDescription = computed({
    get: () => (recording.value?.description as string | undefined) || '',
    set: (value: string) => setRecording({ description: value || null }),
});
</script>

<template>
    <div class="rounded-xl border-2 border-foreground bg-card shadow-2xs">
        <button
            type="button"
            class="flex w-full cursor-pointer items-center justify-between gap-3 p-4 text-sm"
            :data-testid="`youtube-settings-toggle-${platformIndex}`"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span
                    class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs"
                >
                    <img
                        :src="getPlatformLogo(Platform.YouTube)"
                        alt="YouTube"
                        class="size-full object-cover"
                    />
                </span>
                <span class="truncate font-bold text-foreground">{{
                    $t('posts.form.youtube.settings')
                }}</span>
                <span
                    v-if="socialAccount?.username"
                    class="truncate font-medium text-foreground/60"
                    >·&nbsp;@{{ socialAccount.username }}</span
                >
            </span>
            <IconChevronUp
                v-if="open"
                class="size-4 shrink-0 text-foreground/60"
            />
            <IconChevronDown
                v-else
                class="size-4 shrink-0 text-foreground/60"
            />
        </button>
        <div
            v-if="open"
            class="space-y-5 border-t-2 border-foreground/10 px-4 pt-4 pb-4"
        >
            <div
                v-if="socialAccount"
                class="flex items-center gap-3 rounded-lg bg-foreground/5 p-3"
            >
                <Avatar
                    :src="socialAccount.avatar_url"
                    :name="socialAccount.display_label"
                    class="size-9 shrink-0 rounded-full border-2 border-foreground shadow-2xs"
                />
                <div class="min-w-0 flex-1">
                    <p
                        class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                    >
                        {{ $t('posts.form.youtube.posting_to') }}
                    </p>
                    <p class="truncate text-sm">
                        <span class="font-bold text-foreground">{{
                            socialAccount.display_label
                        }}</span>
                        <span
                            v-if="socialAccount.username"
                            class="font-medium text-foreground/60"
                            >&nbsp;@{{ socialAccount.username }}</span
                        >
                    </p>
                </div>
            </div>
            <div class="space-y-2">
                <Label
                    :for="descriptionId"
                    class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                >
                    {{ $t('posts.form.youtube.description') }}
                </Label>
                <Textarea
                    :id="descriptionId"
                    v-model="description"
                    :data-testid="descriptionId"
                    :disabled="disabled"
                    :aria-invalid="descriptionError ? true : undefined"
                    :placeholder="
                        $t('posts.form.youtube.description_placeholder')
                    "
                    class="field-sizing-fixed min-h-32 w-full resize-y"
                />
                <p
                    class="text-xs tabular-nums"
                    :class="descriptionError ? 'text-rose-600' : 'text-foreground/60'"
                >
                    {{
                        $t('posts.form.youtube.description_bytes', {
                            used: usedBytes.toString(),
                            limit: YOUTUBE_DESCRIPTION_MAX_BYTES.toString(),
                        })
                    }}
                </p>
                <InputError :message="descriptionError" />
            </div>
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p
                        class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                    >
                        {{ $t('posts.form.youtube.title') }}
                    </p>
                    <span
                        class="text-[11px] font-medium"
                        :class="
                            title.length > TITLE_MAX
                                ? 'text-destructive'
                                : 'text-foreground/50'
                        "
                        >{{ title.length }}/{{ TITLE_MAX }}</span
                    >
                </div>
                <Input
                    v-model="title"
                    type="text"
                    :maxlength="TITLE_MAX"
                    :placeholder="$t('posts.form.youtube.title_placeholder')"
                    :disabled="disabled"
                />
                <p class="text-xs text-foreground/60">
                    {{ $t('posts.form.youtube.title_hint') }}
                </p>
            </div>
            <div class="space-y-2">
                <p
                    class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                >
                    {{ $t('posts.form.youtube.tags') }}
                </p>
                <Input
                    v-model="tags"
                    type="text"
                    :placeholder="$t('posts.form.youtube.tags_placeholder')"
                    :disabled="disabled"
                />
                <p class="text-xs text-foreground/60">
                    {{ $t('posts.form.youtube.tags_hint') }}
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="space-y-2">
                    <p
                        class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                    >
                        {{ $t('posts.form.youtube.category') }}
                    </p>
                    <Input
                        v-model="categoryId"
                        type="text"
                        placeholder="22"
                        :disabled="disabled"
                    />
                </div>
                <div class="space-y-2">
                    <p
                        class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                    >
                        {{ $t('posts.form.youtube.language') }}
                    </p>
                    <Input
                        v-model="defaultLanguage"
                        type="text"
                        placeholder="en"
                        :disabled="disabled"
                    />
                </div>
            </div>
            <div class="space-y-2">
                <p
                    class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                >
                    {{ $t('posts.form.youtube.location') }}
                </p>
                <Input
                    v-model="recDescription"
                    type="text"
                    :placeholder="$t('posts.form.youtube.location_placeholder')"
                    :disabled="disabled"
                />
                <div class="grid grid-cols-2 gap-3">
                    <Input
                        v-model="recLat"
                        type="text"
                        :placeholder="$t('posts.form.youtube.lat')"
                        :disabled="disabled"
                    />
                    <Input
                        v-model="recLng"
                        type="text"
                        :placeholder="$t('posts.form.youtube.lng')"
                        :disabled="disabled"
                    />
                </div>
            </div>
            <div class="space-y-2">
                <Label
                    :for="firstCommentId"
                    class="text-[11px] font-black tracking-widest text-foreground/60 uppercase"
                >
                    {{ $t('posts.form.first_comment.label') }}
                </Label>
                <Textarea
                    :id="firstCommentId"
                    v-model="firstComment"
                    :data-testid="firstCommentId"
                    :disabled="disabled"
                    :maxlength="FIRST_COMMENT_MAX"
                    :aria-invalid="firstCommentError ? true : undefined"
                    :placeholder="$t('posts.form.first_comment.placeholder')"
                    class="field-sizing-fixed min-h-20 w-full resize-y"
                />
                <p
                    class="text-xs text-foreground/60 tabular-nums"
                    :class="
                        firstComment.length > FIRST_COMMENT_MAX
                            ? 'text-rose-600'
                            : 'text-foreground/60'
                    "
                >
                    {{ firstComment.length }}/{{ FIRST_COMMENT_MAX }}
                </p>
                <p class="text-xs text-foreground/60">
                    {{ $t('posts.form.first_comment.hint') }}
                </p>
                <InputError :message="firstCommentError" />
            </div>
        </div>
    </div>
</template>
