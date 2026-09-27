<script setup lang="ts">
import { IconChevronDown, IconChevronUp } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Avatar } from '@/components/ui/avatar';
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
        </div>
    </div>
</template>
