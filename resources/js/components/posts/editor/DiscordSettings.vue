<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconPlus, IconX } from '@tabler/icons-vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import {
    channels as channelsRoute,
    mentions as mentionsRoute,
} from '@/actions/App/Http/Controllers/App/DiscordController';
import HexColorInput from '@/components/HexColorInput.vue';
import InputError from '@/components/InputError.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePageErrors } from '@/composables/usePageErrors';

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

const DISCORD_BLURPLE = '#5865f2';

const props = withDefaults(
    defineProps<{
        socialAccount: SocialAccount | null;
        meta: Record<string, any>;
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const updateMeta = (patch: Record<string, any>) =>
    emit('update:meta', { ...props.meta, ...patch });

const channels = ref<DiscordChannel[]>([]);
const channelsLoading = ref(false);
const channelsHttp = useHttp<
    Record<string, never>,
    { channels: DiscordChannel[] }
>();

const loadChannels = async () => {
    if (!props.socialAccount || channelsLoading.value) {
        return;
    }

    channelsLoading.value = true;

    try {
        const { channels: list } = await channelsHttp.get(
            channelsRoute.url(props.socialAccount.id),
        );
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

const channelOptions = computed<DiscordChannel[]>(() => {
    if (
        channelId.value &&
        !channels.value.some((channel) => channel.id === channelId.value)
    ) {
        return [
            { id: channelId.value, name: channelId.value },
            ...channels.value,
        ];
    }

    return channels.value;
});

const channelSelectOptions = computed(() =>
    channelOptions.value.map((channel) => ({
        value: channel.id,
        label: `#${channel.name}`,
    })),
);

watch(
    [channelId, channels],
    () => {
        const name = channels.value.find(
            (channel) => channel.id === channelId.value,
        )?.name;

        if (name && props.meta?.channel_name !== name) {
            updateMeta({ channel_name: name });
        }
    },
    { immediate: true },
);

const errors = usePageErrors();
const channelError = computed<string | undefined>(() => {
    if (props.meta?.channel_id) {
        return undefined;
    }

    return Object.entries(errors.value).find(([key]) =>
        key.endsWith('.meta.channel_id'),
    )?.[1];
});

const mentionQuery = ref('');
const mentionResults = ref<MentionTarget[]>([]);
const mentionsHttp = useHttp<
    Record<string, never>,
    { mentions: MentionTarget[] }
>();
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
                mentionsRoute.url(props.socialAccount!.id, {
                    query: { q: query },
                }),
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
    updateMeta({
        mentions: [...mentions.value, { token, label: target.label }],
    });
    mentionQuery.value = '';
    mentionResults.value = [];
};

const removeMention = (token: string) =>
    updateMeta({
        mentions: mentions.value.filter((mention) => mention.token !== token),
    });

const embeds = computed<EmbedDraft[]>(() =>
    Array.isArray(props.meta?.embeds)
        ? (props.meta!.embeds as EmbedDraft[])
        : [],
);

const addEmbed = () => updateMeta({ embeds: [...embeds.value, {}] });
const removeEmbed = (index: number) =>
    updateMeta({ embeds: embeds.value.filter((_, i) => i !== index) });
const updateEmbed = (index: number, patch: Partial<EmbedDraft>) =>
    updateMeta({
        embeds: embeds.value.map((embed, i) =>
            i === index ? { ...embed, ...patch } : embed,
        ),
    });
</script>

<template>
    <SettingsSection>
        <SettingsRow :label="$t('posts.form.discord.channel')" align-top>
            <SearchableSelect
                v-model="channelId"
                :options="channelSelectOptions"
                :placeholder="
                    channelsLoading
                        ? $t('posts.form.discord.loading_channels')
                        : $t('posts.form.discord.select_channel')
                "
                :search-placeholder="$t('posts.form.discord.search_channel')"
                :empty-text="$t('posts.form.discord.no_channels')"
                :disabled="disabled || channelsLoading"
                :invalid="!!channelError"
            />
            <InputError :message="channelError" />
        </SettingsRow>

        <SettingsRow :label="$t('posts.form.discord.mentions')" align-top>
            <div v-if="mentions.length" class="flex flex-wrap gap-1.5">
                <span
                    v-for="mention in mentions"
                    :key="mention.token"
                    class="inline-flex items-center gap-1 rounded-md border border-border bg-muted px-2 py-0.5 text-xs font-medium text-foreground"
                >
                    {{ mention.label }}
                    <button
                        type="button"
                        :disabled="disabled"
                        class="text-muted-foreground hover:text-foreground"
                        @click="removeMention(mention.token)"
                    >
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
                    class="absolute z-10 mt-1 max-h-48 w-full overflow-y-auto rounded-md border border-border bg-popover shadow-md"
                >
                    <li
                        v-for="target in mentionResults"
                        :key="target.type + target.id"
                    >
                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-center px-3 py-1.5 text-left text-sm hover:bg-accent"
                            @click="addMention(target)"
                        >
                            {{ target.label }}
                        </button>
                    </li>
                </ul>
            </div>
        </SettingsRow>

        <SettingsRow :label="$t('posts.form.discord.embeds')" align-top>
            <div
                v-for="(embed, index) in embeds"
                :key="index"
                class="space-y-2 rounded-md border border-border p-3"
            >
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-muted-foreground"
                        >{{ $t('posts.form.discord.embed') }}
                        {{ index + 1 }}</span
                    >
                    <button
                        type="button"
                        :disabled="disabled"
                        class="text-muted-foreground hover:text-destructive-text"
                        @click="removeEmbed(index)"
                    >
                        <IconX class="size-3.5" />
                    </button>
                </div>
                <Input
                    :model-value="embed.title"
                    :disabled="disabled"
                    :placeholder="$t('posts.form.discord.embed_title')"
                    @update:model-value="
                        updateEmbed(index, { title: String($event) })
                    "
                />
                <textarea
                    :value="embed.description"
                    :disabled="disabled"
                    :placeholder="$t('posts.form.discord.embed_description')"
                    rows="2"
                    class="w-full rounded-md border border-input bg-card px-2 py-1 text-sm transition-[color,box-shadow] outline-none placeholder:text-subtle-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50"
                    @input="
                        updateEmbed(index, {
                            description: ($event.target as HTMLTextAreaElement)
                                .value,
                        })
                    "
                />
                <Input
                    :model-value="embed.url"
                    :disabled="disabled"
                    :placeholder="$t('posts.form.discord.embed_url')"
                    @update:model-value="
                        updateEmbed(index, { url: String($event) })
                    "
                />
                <Input
                    :model-value="embed.image"
                    :disabled="disabled"
                    :placeholder="$t('posts.form.discord.embed_image')"
                    @update:model-value="
                        updateEmbed(index, { image: String($event) })
                    "
                />
                <div class="space-y-1">
                    <p class="text-xs text-muted-foreground">
                        {{ $t('posts.form.discord.embed_color') }}
                    </p>
                    <HexColorInput
                        :model-value="embed.color || DISCORD_BLURPLE"
                        :disabled="disabled"
                        :placeholder="DISCORD_BLURPLE"
                        @update:model-value="
                            (value) =>
                                updateEmbed(index, {
                                    color: value ?? undefined,
                                })
                        "
                    />
                </div>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="disabled"
                data-testid="discord-add-embed"
                @click="addEmbed"
            >
                <IconPlus class="size-3.5" />
                {{ $t('posts.form.discord.add_embed') }}
            </Button>
        </SettingsRow>
    </SettingsSection>
</template>
