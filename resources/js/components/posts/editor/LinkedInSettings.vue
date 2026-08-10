<script setup lang="ts">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { isDocumentMedia } from '@/composables/useMedia';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import type { MediaItem } from '@/types/media';
import { Platform } from '@/types/platform';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

interface Props {
    socialAccount: SocialAccount | null;
    platform: string;
    media: MediaItem[];
    meta?: Record<string, any>;
    disabled?: boolean;
    previewOnly?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    meta: () => ({}),
    disabled: false,
    previewOnly: false,
});

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const open = ref(false);

const isPage = computed(() => props.platform === Platform.LinkedInPage);

const pdfDocument = computed(
    () => props.media.find((item) => isDocumentMedia(item)) ?? null,
);

const hasPdf = computed(() => pdfDocument.value !== null);

const documentTitle = computed({
    get: () =>
        (props.meta?.document_title as string | undefined) ||
        pdfDocument.value?.original_filename ||
        '',
    set: (value: string) => emit('update:meta', { ...props.meta, document_title: value || null }),
});
</script>

<template>
    <div v-if="hasPdf" class="rounded-xl border-2 border-foreground bg-card shadow-2xs">
        <button
            type="button"
            class="flex w-full cursor-pointer items-center justify-between gap-3 p-4 text-sm"
            @click="open = !open"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                    <img :src="getPlatformLogo(platform)" alt="LinkedIn" class="size-full object-cover" />
                </span>
                <span class="truncate font-bold text-foreground">{{ isPage ? $t('posts.form.linkedin.settings_page') : $t('posts.form.linkedin.settings') }}</span>
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
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.linkedin.posting_to') }}</p>
                    <p class="truncate text-sm">
                        <span class="font-bold text-foreground">{{ socialAccount.display_label }}</span>
                        <span v-if="socialAccount?.username" class="font-medium text-foreground/60">&nbsp;@{{ socialAccount.username }}</span>
                    </p>
                </div>
            </div>

            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.linkedin.document_title') }}</p>
                <Input
                    v-model="documentTitle"
                    type="text"
                    :placeholder="$t('posts.form.linkedin.document_title_placeholder')"
                    :disabled="disabled || previewOnly"
                />
            </div>
        </div>
    </div>
</template>
