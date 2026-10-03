<script setup lang="ts">
import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { CommandItem } from '@/components/ui/command';
import { channelName } from '@/types/channel';
import type {
    CommandPaletteEntry,
    CommandPaletteLabel,
    CommandPaletteTitle,
} from '@/types/command-palette';

type Translate = (key: string, replacements?: Record<string, string>) => string;

defineProps<{
    entry: CommandPaletteEntry;
    value: string;
    testId: string;
}>();

const emit = defineEmits<{
    select: [entry: CommandPaletteEntry];
}>();

const label = (value: CommandPaletteLabel, translate: Translate): string =>
    'text' in value ? value.text : translate(value.key);

const title = (value: CommandPaletteTitle, translate: Translate): string =>
    'path' in value
        ? translate('command_palette.path', {
              parent: label(value.path[0], translate),
              child: label(value.path[1], translate),
          })
        : label(value, translate);
</script>

<template>
    <CommandItem
        :value="value"
        class="min-h-11 gap-3 rounded-lg px-4 py-3 data-[highlighted]:bg-secondary data-[highlighted]:text-foreground data-[selected]:bg-secondary data-[selected]:text-foreground"
        :data-testid="testId"
        @select="emit('select', entry)"
    >
        <ChannelAvatar
            :status="entry.channel.status"
            :account-id="entry.channel.id"
            v-if="entry.channel"
            :platform="entry.channel.platform"
            :src="entry.channel.avatar_url"
            :name="channelName(entry.channel)"
            :size="28"
            ring="popover"
            :class="entry.subtitle ? 'self-start' : 'self-center'"
        />
        <component
            :is="entry.icon"
            v-else-if="entry.icon"
            :class="[
                'size-4 shrink-0 text-foreground',
                entry.subtitle ? 'mt-0.5 self-start' : 'self-center',
            ]"
            :data-testid="`${testId}-icon`"
        />
        <span class="grid min-w-0 flex-1">
            <span class="truncate text-sm leading-5 text-foreground">{{
                title(entry.title, $t)
            }}</span>
            <span
                v-if="entry.subtitle"
                class="truncate text-xs leading-[18px] text-muted-foreground"
                >{{ label(entry.subtitle, $t) }}</span
            >
        </span>
        <span
            v-if="entry.count"
            class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary-subtle px-1.5 text-xs font-medium text-primary-text tabular-nums"
            :data-testid="`${testId}-count`"
            >{{ entry.count }}</span
        >
    </CommandItem>
</template>
