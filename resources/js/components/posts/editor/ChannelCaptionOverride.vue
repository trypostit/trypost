<script setup lang="ts">
import { IconChevronDown, IconChevronUp, IconPencil } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { Textarea } from '@/components/ui/textarea';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import type { Channel } from '@/types/channel';

const props = withDefaults(defineProps<{
    channel: Channel;
    disabled?: boolean;
    previewOnly?: boolean;
}>(), {
    disabled: false,
    previewOnly: false,
});

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const override = computed({
    get: () => (props.channel.meta?.content as string | undefined) || '',
    set: (value: string) => emit('update:meta', { ...props.channel.meta, content: value || null }),
});

// Раскрыта сразу, если переопределение уже задано.
const open = ref(override.value !== '');

const limit = computed(() => props.channel.contentLimit ?? null);
const overLimit = computed(() => limit.value !== null && override.value.length > limit.value);
</script>

<template>
    <div class="rounded-xl border-2 border-dashed border-foreground/40 bg-card">
        <button
            type="button"
            class="flex w-full cursor-pointer items-center justify-between gap-3 px-4 py-2.5 text-sm"
            @click="open = !open"
        >
            <span class="flex min-w-0 items-center gap-2">
                <IconPencil class="size-4 shrink-0 text-foreground/60" />
                <span class="inline-flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-full border border-foreground/40">
                    <img :src="getPlatformLogo(channel.platform)" :alt="getPlatformLabel(channel.platform)" class="size-full object-cover" />
                </span>
                <span class="truncate font-bold text-foreground">{{ $t('posts.form.custom_caption.label', { platform: getPlatformLabel(channel.platform) }) }}</span>
                <span v-if="override" class="shrink-0 rounded-md bg-violet-100 px-1.5 py-0.5 text-[10px] font-black uppercase tracking-wider text-violet-700">{{ $t('posts.form.custom_caption.active') }}</span>
            </span>
            <IconChevronUp v-if="open" class="size-4 shrink-0 text-foreground/60" />
            <IconChevronDown v-else class="size-4 shrink-0 text-foreground/60" />
        </button>

        <div v-if="open" class="space-y-2 border-t-2 border-foreground/10 px-4 pb-4 pt-3">
            <div class="flex items-center justify-between">
                <p class="text-xs text-foreground/60">{{ $t('posts.form.custom_caption.hint') }}</p>
                <span v-if="limit !== null" class="text-[11px] font-medium" :class="overLimit ? 'text-destructive' : 'text-foreground/50'">
                    {{ override.length }}/{{ limit }}
                </span>
            </div>
            <Textarea
                v-model="override"
                :rows="3"
                :placeholder="$t('posts.form.custom_caption.placeholder')"
                :disabled="disabled || previewOnly"
            />
            <p v-if="overLimit" class="text-xs font-semibold text-destructive">
                {{ $t('posts.form.custom_caption.over_limit', { limit: limit ?? 0 }) }}
            </p>
        </div>
    </div>
</template>
