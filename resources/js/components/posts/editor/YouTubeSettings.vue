<script setup lang="ts">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import { Textarea } from '@/components/ui/textarea';
import { getPlatformLogo } from '@/composables/usePlatformLogo';

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

const DESCRIPTION_MAX = 5000;
const FIRST_COMMENT_MAX = 2200;

const description = computed({
    get: () => (props.meta?.description as string | undefined) || '',
    set: (value: string) => emit('update:meta', { ...props.meta, description: value || null }),
});

const firstComment = computed({
    get: () => (props.meta?.first_comment as string | undefined) || '',
    set: (value: string) => emit('update:meta', { ...props.meta, first_comment: value || null }),
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
                    <img :src="getPlatformLogo(platform)" alt="YouTube" class="size-full object-cover" />
                </span>
                <span class="truncate font-bold text-foreground">{{ $t('posts.form.youtube.settings') }}</span>
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
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.youtube.posting_to') }}</p>
                    <p class="truncate text-sm">
                        <span class="font-bold text-foreground">{{ socialAccount.display_label }}</span>
                        <span v-if="socialAccount?.username" class="font-medium text-foreground/60">&nbsp;@{{ socialAccount.username }}</span>
                    </p>
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.youtube.description') }}</p>
                    <span class="text-[11px] font-medium" :class="description.length > DESCRIPTION_MAX ? 'text-destructive' : 'text-foreground/50'">
                        {{ description.length }}/{{ DESCRIPTION_MAX }}
                    </span>
                </div>
                <Textarea
                    v-model="description"
                    :rows="4"
                    :maxlength="DESCRIPTION_MAX"
                    :placeholder="$t('posts.form.youtube.description_placeholder')"
                    :disabled="disabled || previewOnly"
                />
                <p class="text-xs text-foreground/60">{{ $t('posts.form.youtube.description_hint') }}</p>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.first_comment.label') }}</p>
                    <span class="text-[11px] font-medium" :class="firstComment.length > FIRST_COMMENT_MAX ? 'text-destructive' : 'text-foreground/50'">
                        {{ firstComment.length }}/{{ FIRST_COMMENT_MAX }}
                    </span>
                </div>
                <Textarea
                    v-model="firstComment"
                    :rows="2"
                    :maxlength="FIRST_COMMENT_MAX"
                    :placeholder="$t('posts.form.first_comment.placeholder')"
                    :disabled="disabled || previewOnly"
                />
                <p class="text-xs text-foreground/60">{{ $t('posts.form.first_comment.hint') }}</p>
            </div>
        </div>
    </div>
</template>
