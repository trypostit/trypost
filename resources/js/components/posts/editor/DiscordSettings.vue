<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconChevronDown, IconChevronUp, IconPlus, IconX } from '@tabler/icons-vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import { channels as channelsRoute, mentions as mentionsRoute } from '@/actions/App/Http/Controllers/App/DiscordController';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Avatar } from '@/components/ui/avatar';
import { Input } from '@/components/ui/input';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLogo } from '@/composables/usePlatformLogo';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

interface DiscordChannel {
    id: string;
    name: string;
}

interface MentionTarget {
    id: string;
    label: string;
    type: 'everyone' | 'here' | 'role' | 'user';
}

interface MentionChip {
    token: string;
    label: string;
}

interface EmbedDraft {
    title?: string;
    description?: string;
    url?: string;
    image?: string;
    color?: string;
}

const props = withDefaults(
    defineProps<{
        socialAccount: SocialAccount | null;
        meta: Record<string, any>;
        disabled?: boolean;
        previewOnly?: boolean;
    }>(),
    { disabled: false, previewOnly: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const open = ref(false);

const updateMeta = (patch: Record<string, any>) => emit('update:meta', { ...props.meta, ...patch });

// --- Channel picker (live fetch) ---------------------------------------------
const channels = ref<DiscordChannel[]>([]);
const channelsLoading = ref(false);
const channelsHttp = useHttp<Record<string, never>, { channels: DiscordChannel[] }>();

const loadChannels = async () => {
    if (!props.socialAccount || channelsLoading.value) {
        return;
    }

    channelsLoading.value = true;

    try {
        const { channels: list } = await channelsHttp.get(channelsRoute.url(props.socialAccount.id));
        channels.value = list;
    } catch {
        channels.value = [];
    } finally {
        channelsLoading.value = false;
    }
};

onMounted(loadChannels);

const channelId = computed({
    get: () => (props.meta?.channel_id as string) ?? '',
    set: (value: string) => updateMeta({ channel_id: value || null }),
});

// Keep a saved channel selectable even before the live list loads (or if the
// lookup is unavailable), so editing a post never visually "loses" its channel.
const channelOptions = computed<DiscordChannel[]>(() => {
    if (channelId.value && !channels.value.some((channel) => channel.id === channelId.value)) {
        return [{ id: channelId.value, name: channelId.value }, ...channels.value];
    }

    return channels.value;
});

const channelSelectOptions = computed(() => channelOptions.value.map((channel) => ({ value: channel.id, label: `#${channel.name}` })));

// Persist the channel NAME alongside the id (display-only, for the preview) and
// keep it fresh as the live list loads or the channel is renamed.
watch([channelId, channels], () => {
    const name = channels.value.find((channel) => channel.id === channelId.value)?.name;

    if (name && props.meta?.channel_name !== name) {
        updateMeta({ channel_name: name });
    }
}, { immediate: true });

const errors = usePageErrors();
const channelError = computed<string | undefined>(() => {
    if (props.meta?.channel_id) {
        return undefined;
    }

    return Object.entries(errors.value).find(([key]) => key.endsWith('.meta.channel_id'))?.[1];
});

// --- Mentions (autocomplete chips) -------------------------------------------
const mentionQuery = ref('');
const mentionResults = ref<MentionTarget[]>([]);
const mentionsHttp = useHttp<Record<string, never>, { mentions: MentionTarget[] }>();
const mentions = computed<MentionChip[]>(() => {
    const value = props.meta?.mentions;
    return Array.isArray(value) ? (value as MentionChip[]) : [];
});

const tokenFor = (target: MentionTarget): string =>
    ({
        everyone: '@everyone',
        here: '@here',
        role: `<@&${target.id}>`,
        user: `<@${target.id}>`,
    })[target.type];

let mentionTimer: ReturnType<typeof setTimeout> | undefined;
watch(mentionQuery, (query) => {
    clearTimeout(mentionTimer);
    if (!props.socialAccount || query.trim() === '') {
        mentionResults.value = [];
        return;
    }
    mentionTimer = setTimeout(async () => {
        try {
            const { mentions: list } = await mentionsHttp.get(
                mentionsRoute.url(props.socialAccount!.id, { query: { q: query } }),
            );
            mentionResults.value = list;
        } catch {
            mentionResults.value = [];
        }
    }, 250);
});

onUnmounted(() => clearTimeout(mentionTimer));

const addMention = (target: MentionTarget) => {
    const token = tokenFor(target);
    if (mentions.value.some((mention) => mention.token === token)) {
        return;
    }
    updateMeta({ mentions: [...mentions.value, { token, label: target.label }] });
    mentionQuery.value = '';
    mentionResults.value = [];
};

const removeMention = (token: string) =>
    updateMeta({ mentions: mentions.value.filter((mention) => mention.token !== token) });

// --- Embeds (repeater) -------------------------------------------------------
// Derived straight from meta (like channel/mentions) so it never diverges from
// the persisted/auto-saved state. Inputs are controlled (one-way :value + emit),
// so index keys are safe — Vue patches each reused row to the correct values.
const embeds = computed<EmbedDraft[]>(() => (Array.isArray(props.meta?.embeds) ? (props.meta!.embeds as EmbedDraft[]) : []));

const addEmbed = () => updateMeta({ embeds: [...embeds.value, {}] });
const removeEmbed = (index: number) => updateMeta({ embeds: embeds.value.filter((_, i) => i !== index) });
const updateEmbed = (index: number, patch: Partial<EmbedDraft>) =>
    updateMeta({ embeds: embeds.value.map((embed, i) => (i === index ? { ...embed, ...patch } : embed)) });
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
                    <img :src="getPlatformLogo('discord')" alt="Discord" class="size-full object-cover" />
                </span>
                <span class="truncate font-bold text-foreground">{{ $t('posts.form.discord.settings') }}</span>
                <span v-if="socialAccount?.display_label" class="truncate font-medium text-foreground/60">·&nbsp;{{ socialAccount.display_label }}</span>
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
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.discord.posting_to') }}</p>
                    <p class="truncate text-sm font-bold text-foreground">{{ socialAccount.display_label }}</p>
                </div>
            </div>

            <!-- Channel -->
            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.discord.channel') }}</p>
                <SearchableSelect
                    v-model="channelId"
                    :options="channelSelectOptions"
                    :placeholder="channelsLoading ? $t('posts.form.discord.loading_channels') : $t('posts.form.discord.select_channel')"
                    :search-placeholder="$t('posts.form.discord.search_channel')"
                    :empty-text="$t('posts.form.discord.no_channels')"
                    :disabled="disabled || channelsLoading"
                    :invalid="!!channelError"
                />
                <InputError :message="channelError" />
            </div>

            <!-- Mentions -->
            <div class="space-y-2">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.discord.mentions') }}</p>
                <div v-if="mentions.length" class="flex flex-wrap gap-1.5">
                    <span
                        v-for="mention in mentions"
                        :key="mention.token"
                        class="inline-flex items-center gap-1 rounded-full border-2 border-foreground/30 bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-foreground"
                    >
                        {{ mention.label }}
                        <button type="button" :disabled="disabled" class="text-foreground/50 hover:text-foreground" @click="removeMention(mention.token)">
                            <IconX class="size-3" />
                        </button>
                    </span>
                </div>
                <div class="relative">
                    <Input
                        v-model="mentionQuery"
                        :disabled="disabled"
                        :placeholder="$t('posts.form.discord.search_mention')"
                    />
                    <ul
                        v-if="mentionResults.length"
                        class="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-lg border-2 border-foreground bg-card shadow-2xs"
                    >
                        <li v-for="target in mentionResults" :key="target.type + target.id">
                            <button
                                type="button"
                                class="flex w-full cursor-pointer items-center px-3 py-1.5 text-left text-sm hover:bg-foreground/5"
                                @click="addMention(target)"
                            >
                                {{ target.label }}
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Embeds -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.discord.embeds') }}</p>
                    <button
                        type="button"
                        :disabled="disabled"
                        class="inline-flex cursor-pointer items-center gap-1 text-xs font-bold text-foreground/70 hover:text-foreground disabled:opacity-50"
                        @click="addEmbed"
                    >
                        <IconPlus class="size-3.5" />
                        {{ $t('posts.form.discord.add_embed') }}
                    </button>
                </div>
                <div
                    v-for="(embed, index) in embeds"
                    :key="index"
                    class="space-y-2 rounded-lg border-2 border-foreground/20 p-3"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-black uppercase tracking-widest text-foreground/50">{{ $t('posts.form.discord.embed') }} {{ index + 1 }}</span>
                        <button type="button" :disabled="disabled" class="text-foreground/50 hover:text-rose-600" @click="removeEmbed(index)">
                            <IconX class="size-3.5" />
                        </button>
                    </div>
                    <Input
                        :model-value="embed.title"
                        :disabled="disabled"
                        :placeholder="$t('posts.form.discord.embed_title')"
                        @update:model-value="updateEmbed(index, { title: String($event) })"
                    />
                    <textarea
                        :value="embed.description"
                        :disabled="disabled"
                        :placeholder="$t('posts.form.discord.embed_description')"
                        rows="2"
                        class="w-full rounded-lg border-2 border-foreground/30 bg-card px-3 py-2 text-sm transition-colors hover:border-foreground focus:border-foreground focus:outline-none disabled:opacity-50"
                        @input="updateEmbed(index, { description: ($event.target as HTMLTextAreaElement).value })"
                    />
                    <Input
                        :model-value="embed.url"
                        :disabled="disabled"
                        :placeholder="$t('posts.form.discord.embed_url')"
                        @update:model-value="updateEmbed(index, { url: String($event) })"
                    />
                    <Input
                        :model-value="embed.image"
                        :disabled="disabled"
                        :placeholder="$t('posts.form.discord.embed_image')"
                        @update:model-value="updateEmbed(index, { image: String($event) })"
                    />
                    <div class="flex items-center gap-2">
                        <input
                            type="color"
                            :value="embed.color || '#5865F2'"
                            :disabled="disabled"
                            class="h-8 w-12 cursor-pointer rounded border-2 border-foreground/30 disabled:opacity-50"
                            @input="updateEmbed(index, { color: ($event.target as HTMLInputElement).value })"
                        />
                        <span class="text-xs font-medium text-foreground/60">{{ $t('posts.form.discord.embed_color') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
