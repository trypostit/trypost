<script setup lang="ts">
import { IconAlertTriangle, IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import { Avatar } from '@/components/ui/avatar';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxGroup,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    ComboboxTrigger,
} from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { getMediaValidationWarning } from '@/composables/useMedia';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { fallbackImageCapableVariant, filterImageCapableVariants } from '@/lib/aiGenerateVariants';
import type { PinterestBoard } from '@/types';
import { ContentType } from '@/types/content-type';
import type { MediaItem } from '@/types/media';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

interface BoardOption {
    value: string;
    label: string;
}

interface Props {
    socialAccount: SocialAccount | null;
    contentType: string;
    media: MediaItem[];
    boards: PinterestBoard[];
    boardsTruncated?: boolean;
    meta: Record<string, any>;
    disabled?: boolean;
    previewOnly?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
    previewOnly: false,
    boardsTruncated: false,
});

const emit = defineEmits<{
    'update:contentType': [value: string];
    'update:meta': [value: Record<string, any>];
}>();

const open = ref(false);

const allVariants = [
    { value: ContentType.PinterestPin, labelKey: 'posts.form.pinterest.variant.pin' },
    { value: ContentType.PinterestVideoPin, labelKey: 'posts.form.pinterest.variant.video_pin' },
    { value: ContentType.PinterestCarousel, labelKey: 'posts.form.pinterest.variant.carousel' },
] as const;

// Generate node only creates images — hide Video Pin there.
const variants = computed(() => filterImageCapableVariants(allVariants, props.previewOnly));

watch(
    () => [props.previewOnly, props.contentType, variants.value] as const,
    () => {
        const fallback = fallbackImageCapableVariant(props.contentType, variants.value);
        if (fallback) {
            emit('update:contentType', fallback);
        }
    },
    { immediate: true },
);

const pickVariant = (value: string) => {
    if (props.disabled) return;
    emit('update:contentType', value);
};

const warning = computed(() => getMediaValidationWarning(props.contentType, props.media));

const boardOptions = computed<BoardOption[]>(() =>
    props.boards.map((b) => ({ value: b.id, label: b.name })),
);

const selectedBoard = computed<BoardOption | undefined>({
    get: () => boardOptions.value.find((b) => b.value === props.meta?.board_id),
    set: (board) => emit('update:meta', { ...props.meta, board_id: board?.value ?? null }),
});

const pinTitle = computed({
    get: () => (props.meta?.title as string | undefined) || '',
    set: (value: string) => {
        // Whitespace-only clears the field; keep interior spaces while typing.
        emit('update:meta', { ...props.meta, title: value.trim() === '' ? null : value });
    },
});

const pinLink = computed({
    get: () => (props.meta?.link as string | undefined) || '',
    set: (value: string) => {
        // Whitespace-only clears the field; keep partial URLs while typing.
        emit('update:meta', { ...props.meta, link: value.trim() === '' ? null : value });
    },
});

// Surface backend validation errors keyed by platform index
// (`platforms.0.meta.*`). Suffix match avoids threading the index through props.
// Board clears as soon as one is picked so the user doesn't see a stale error.
const errors = usePageErrors();
const boardError = computed<string | undefined>(() => {
    if (props.meta?.board_id) {
        return undefined;
    }

    return Object.entries(errors.value).find(([key]) => key.endsWith('.meta.board_id'))?.[1];
});
const titleError = computed<string | undefined>(() => {
    return Object.entries(errors.value).find(([key]) => key.endsWith('.meta.title'))?.[1];
});
const linkError = computed<string | undefined>(() => {
    return Object.entries(errors.value).find(([key]) => key.endsWith('.meta.link'))?.[1];
});
</script>

<template>
    <div class="rounded-xl border-2 border-foreground bg-card shadow-2xs">
        <button
            type="button"
            class="flex w-full cursor-pointer items-center justify-between gap-3 p-4 text-sm"
            @click="open = !open"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                    <img :src="getPlatformLogo('pinterest')" alt="Pinterest" class="size-full object-cover" />
                </span>
                <span class="truncate font-bold text-foreground">{{ $t('posts.form.pinterest.settings') }}</span>
                <span v-if="socialAccount?.username" class="truncate font-medium text-foreground/60">·&nbsp;@{{ socialAccount.username }}</span>
            </span>
            <IconChevronUp v-if="open" class="size-4 shrink-0 text-foreground/60" />
            <IconChevronDown v-else class="size-4 shrink-0 text-foreground/60" />
        </button>

        <div v-if="open" class="space-y-5 border-t-2 border-foreground/10 px-4 pb-4 pt-4">
            <div v-if="socialAccount" class="flex items-center gap-3 rounded-lg bg-foreground/5 p-3">
                <Avatar
                    :src="socialAccount.avatar_url"
                    :name="socialAccount.display_label"
                    class="size-9 shrink-0 rounded-full border-2 border-foreground shadow-2xs"
                />
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.pinterest.posting_to') }}</p>
                    <p class="truncate text-sm">
                        <span class="font-bold text-foreground">{{ socialAccount.display_label }}</span>
                        <span v-if="socialAccount?.username" class="font-medium text-foreground/60">&nbsp;@{{ socialAccount.username }}</span>
                    </p>
                </div>
            </div>

            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.pinterest.variant_label') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="variant in variants"
                        :key="variant.value"
                        type="button"
                        class="cursor-pointer rounded-full border-2 px-3 py-1 text-xs font-bold uppercase tracking-widest transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="contentType === variant.value
                            ? 'border-foreground bg-violet-100 text-foreground shadow-2xs'
                            : 'border-foreground/30 text-foreground/70 hover:border-foreground hover:text-foreground'"
                        :disabled="disabled"
                        @click="pickVariant(variant.value)"
                    >
                        {{ $t(variant.labelKey) }}
                    </button>
                </div>
            </div>

            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.pinterest.board') }}</p>
                <p
                    v-if="boards.length === 0"
                    class="flex items-start gap-2 rounded-lg border-2 border-foreground/30 bg-foreground/5 p-2 text-xs font-semibold text-foreground/60"
                >
                    <IconAlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    {{ $t('posts.form.pinterest.no_boards') }}
                </p>
                <template v-else>
                    <Combobox
                        v-model="selectedBoard"
                        :display-value="(b: BoardOption | undefined) => b?.label ?? ''"
                        :disabled="disabled"
                    >
                        <ComboboxAnchor class="w-full">
                            <ComboboxTrigger as-child>
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between rounded-lg border-2 bg-card px-3 py-2 text-sm font-medium text-foreground transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                                    :class="boardError ? 'border-rose-500' : 'border-foreground/30 hover:border-foreground'"
                                    :disabled="disabled"
                                >
                                    <span :class="selectedBoard ? 'text-foreground' : 'text-foreground/50'">
                                        {{ selectedBoard ? selectedBoard.label : $t('posts.form.pinterest.select_board') }}
                                    </span>
                                    <IconChevronDown class="size-4 shrink-0 text-foreground/60" />
                                </button>
                            </ComboboxTrigger>
                        </ComboboxAnchor>
                        <ComboboxList>
                            <ComboboxInput :placeholder="$t('posts.form.pinterest.search_board')" />
                            <ComboboxEmpty>{{ $t('posts.form.pinterest.no_board_found') }}</ComboboxEmpty>
                            <ComboboxGroup>
                                <ComboboxItem
                                    v-for="board in boardOptions"
                                    :key="board.value"
                                    :value="board"
                                >
                                    {{ board.label }}
                                </ComboboxItem>
                            </ComboboxGroup>
                        </ComboboxList>
                    </Combobox>
                    <InputError :message="boardError" />
                    <p
                        v-if="boardsTruncated"
                        class="flex items-start gap-2 rounded-lg border-2 border-foreground/30 bg-foreground/5 p-2 text-xs font-semibold text-foreground/60"
                    >
                        <IconAlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                        {{ $t('posts.form.pinterest.boards_truncated') }}
                    </p>
                </template>
            </div>

            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.pinterest.title') }}</p>
                <Input
                    v-model="pinTitle"
                    type="text"
                    :placeholder="$t('posts.form.pinterest.title_placeholder')"
                    :disabled="disabled || previewOnly"
                    :class="titleError ? 'border-rose-500' : undefined"
                />
                <InputError :message="titleError" />
            </div>

            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.pinterest.link') }}</p>
                <Input
                    v-model="pinLink"
                    type="text"
                    :placeholder="$t('posts.form.pinterest.link_placeholder')"
                    :disabled="disabled || previewOnly"
                    :class="linkError ? 'border-rose-500' : undefined"
                />
                <InputError :message="linkError" />
            </div>

            <p
                v-if="warning && !previewOnly"
                class="flex items-start gap-2 rounded-lg border-2 border-foreground bg-rose-50 p-2 text-xs font-semibold text-rose-700"
            >
                <IconAlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                {{ $t(`posts.form.warnings.${warning.key}`, warning.params) }}
            </p>
        </div>
    </div>
</template>
