<script setup lang="ts">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Avatar } from '@/components/ui/avatar';
import { Textarea } from '@/components/ui/textarea';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import {
    getYouTubeDescriptionIssue,
    YOUTUBE_DESCRIPTION_MAX_BYTES,
    youtubeDescriptionBytes,
} from '@/lib/youtubeDescription';
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
    platformIndex: number;
    meta: Record<string, any>;
    disabled?: boolean;
    previewOnly?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
    previewOnly: false,
});
const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();
const open = ref(false);
const description = computed({
    get: () =>
        typeof props.meta.description === 'string'
            ? props.meta.description
            : '',
    set: (value: string) =>
        emit('update:meta', {
            ...props.meta,
            description: value.trim() === '' ? null : value,
        }),
});
const usedBytes = computed(() => youtubeDescriptionBytes(description.value));
const issueKey = computed(() =>
    getYouTubeDescriptionIssue(props.meta.description),
);
const errors = usePageErrors();
const descriptionError = computed(
    () => errors.value[`platforms.${props.platformIndex}.meta.description`],
);
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
                <label
                    :for="`youtube-description-${platformIndex}`"
                    class="text-sm font-bold"
                    >{{ $t('posts.form.youtube.description') }}</label
                >
                <Textarea
                    :id="`youtube-description-${platformIndex}`"
                    v-model="description"
                    :data-testid="`youtube-description-${platformIndex}`"
                    :disabled="disabled || previewOnly"
                    :placeholder="
                        $t('posts.form.youtube.description_placeholder')
                    "
                    class="field-sizing-fixed min-h-32 w-full resize-y"
                />
                <p
                    class="text-xs tabular-nums"
                    :class="issueKey ? 'text-rose-600' : 'text-foreground/60'"
                >
                    {{
                        $t('posts.form.youtube.description_bytes', {
                            used: usedBytes,
                            limit: YOUTUBE_DESCRIPTION_MAX_BYTES,
                        })
                    }}
                </p>
                <InputError
                    :message="issueKey ? $t(issueKey) : descriptionError"
                />
            </div>
        </div>
    </div>
</template>
