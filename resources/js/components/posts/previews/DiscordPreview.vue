<script setup lang="ts">
import { computed } from 'vue';

import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import date from '@/date';

import type { PreviewProps } from './types';

interface EmbedDraft {
    title?: string;
    description?: string;
    url?: string;
    image?: string;
    color?: string;
}

interface MentionChip {
    token: string;
    label: string;
}

const props = defineProps<PreviewProps>();

const embeds = computed((): EmbedDraft[] =>
    Array.isArray(props.meta?.embeds) ? props.meta.embeds : [],
);
const mentions = computed((): MentionChip[] =>
    Array.isArray(props.meta?.mentions) ? props.meta.mentions : [],
);
</script>

<template>
    <PreviewTextPost
        data-testid="discord-preview"
        :account="socialAccount"
        :content="content"
        :media="media"
        :handle="`#${meta?.channel_name || 'channel'}`"
        :caption="
            date.formatDiscordPreview(
                postedAt,
                $t('common.date_range_picker.today'),
            )
        "
        variant="stacked"
    >
        <template #badge>
            <span
                class="rounded bg-secondary px-1 text-[10px] font-semibold tracking-wide text-secondary-foreground uppercase"
                >Bot</span
            >
        </template>
        <div v-if="mentions.length" class="flex flex-wrap gap-1">
            <span
                v-for="mention in mentions"
                :key="mention.token"
                class="rounded bg-info-subtle px-1 font-medium text-info-text"
                >{{ mention.label }}</span
            >
        </div>
        <div
            v-for="(embed, index) in embeds"
            :key="index"
            class="space-y-1 rounded-md border border-l-4 bg-muted p-3"
            :style="embed.color ? { borderLeftColor: embed.color } : undefined"
        >
            <p v-if="embed.title" class="font-semibold text-info">
                {{ embed.title }}
            </p>
            <p
                v-if="embed.description"
                class="text-sm whitespace-pre-wrap"
                >{{ embed.description }}</p
            >
            <img
                v-if="embed.image"
                :src="embed.image"
                alt=""
                class="max-h-48 rounded object-cover"
            />
        </div>
    </PreviewTextPost>
</template>
