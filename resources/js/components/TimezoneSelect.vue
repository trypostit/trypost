<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconCheck, IconChevronDown, IconMapPin, IconSearch, IconUser, IconWorld } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { orderChannels } from '@/composables/useChannelOrder';
import { cn } from '@/lib/utils';
import type { User } from '@/types';
import { channelName, type SidebarChannel } from '@/types/channel';
import type { TimezoneOption } from '@/types/posting-schedule';

interface Suggestion {
    option: TimezoneOption;
    isUser: boolean;
    isBrowser: boolean;
    channels: SidebarChannel[];
}

const STACK_LIMIT = 2;
const TOOLTIP_LIMIT = 6;

const props = defineProps<{
    options: TimezoneOption[];
    testid?: string;
    compact?: boolean;
    variant?: 'outline' | 'ghost';
}>();

const model = defineModel<string>({ required: true });
const page = usePage();
const search = ref('');
const open = ref(false);
const id = computed(() => props.testid ?? 'timezone');

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const userTimezone = computed(() => (page.props.auth.user as User | null)?.timezone ?? null);
const channels = computed<SidebarChannel[]>(() => orderChannels((page.props.channels as SidebarChannel[] | undefined) ?? []));

const selected = computed(() => props.options.find((option) => option.value === model.value));

const suggestions = computed<Suggestion[]>(() => {
    const byValue = new Map(props.options.map((option) => [option.value, option]));
    const rows = new Map<string, Suggestion>();
    const row = (value: string | null): Suggestion | null => {
        const option = value ? byValue.get(value) : undefined;

        if (!option) {
            return null;
        }

        if (!rows.has(option.value)) {
            rows.set(option.value, { option, isUser: false, isBrowser: false, channels: [] });
        }

        return rows.get(option.value) ?? null;
    };

    const user = row(userTimezone.value);

    if (user) {
        user.isUser = true;
    }

    const browser = row(browserTimezone);

    if (browser) {
        browser.isBrowser = true;
    }

    channels.value.forEach((channel) => {
        row(channel.timezone)?.channels.push(channel);
    });

    return [...rows.values()];
});

const matches = (option: TimezoneOption): boolean => {
    const term = search.value.trim().toLowerCase();

    return term === '' || `${option.label} ${option.value} ${option.offset}`.toLowerCase().includes(term);
};

const suggestedValues = computed(() => new Set(suggestions.value.map((suggestion) => suggestion.option.value)));
const visibleSuggestions = computed(() => suggestions.value.filter((suggestion) => matches(suggestion.option)));
const visibleOptions = computed(() =>
    props.options.filter((option) => !suggestedValues.value.has(option.value) && matches(option)),
);

const slug = (value: string): string => value.replaceAll('/', '-');
const optionTestId = (value: string): string => `${id.value}-option-${slug(value)}`;

const choose = (value: string): void => {
    model.value = value;
    open.value = false;
    search.value = '';
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                :variant="variant ?? 'outline'"
                role="combobox"
                :aria-expanded="open"
                :data-testid="`${id}-trigger`"
                :class="
                    cn(
                        'data-[state=open]:bg-accent',
                        variant === 'ghost' || compact ? 'gap-1' : 'gap-2 font-normal',
                        compact ? 'max-w-full' : 'w-full justify-start',
                    )
                "
            >
                <IconWorld class="size-4 shrink-0 text-muted-foreground" />
                <span class="truncate">
                    {{ selected ? (compact ? selected.label : `${selected.label} (${selected.offset})`) : model }}
                </span>
                <IconChevronDown v-if="compact" class="size-4 shrink-0 text-muted-foreground" />
            </Button>
        </PopoverTrigger>

        <PopoverContent
            :class="cn('p-3', compact ? 'w-[340px] max-w-[calc(100vw-2rem)]' : 'w-(--reka-popover-trigger-width)')"
            :align="compact ? 'end' : 'start'"
        >
            <div class="relative">
                <IconSearch class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    v-model="search"
                    class="pl-8"
                    :data-testid="`${id}-search`"
                    :placeholder="$t('channels.timezone_select.placeholder')"
                    autocomplete="off"
                />
            </div>

            <TooltipProvider :delay-duration="200">
                <div class="mt-3 max-h-72 overflow-y-auto">
                    <p
                        v-if="visibleSuggestions.length === 0 && visibleOptions.length === 0"
                        class="px-2 py-6 text-center text-sm text-muted-foreground"
                    >
                        {{ $t('channels.timezone_select.empty') }}
                    </p>

                    <div v-if="visibleSuggestions.length > 0" :data-testid="`${id}-suggestions`">
                        <p class="px-2 py-1 text-xs text-muted-foreground">
                            {{ $t('channels.timezone_select.suggestions') }}
                        </p>
                        <Tooltip v-for="suggestion in visibleSuggestions" :key="suggestion.option.value">
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    :data-testid="optionTestId(suggestion.option.value)"
                                    class="flex min-h-8 w-full items-center gap-2 rounded-md px-2 py-1 text-start text-sm transition-control hover:bg-accent"
                                    @click="choose(suggestion.option.value)"
                                >
                                    <IconCheck
                                        :class="cn('size-4 shrink-0', model === suggestion.option.value ? 'opacity-100' : 'opacity-0')"
                                    />
                                    <span class="truncate">
                                        {{ suggestion.option.label }}
                                        <span class="text-xs text-muted-foreground">({{ suggestion.option.offset }})</span>
                                    </span>
                                    <span
                                        class="ms-auto flex shrink-0 items-center gap-1.5 text-muted-foreground"
                                        :data-testid="`${id}-suggestion-reasons-${slug(suggestion.option.value)}`"
                                    >
                                        <IconUser v-if="suggestion.isUser" class="size-4" :data-testid="`${id}-yours`" />
                                        <IconMapPin v-if="suggestion.isBrowser" class="size-4" :data-testid="`${id}-detected`" />
                                        <span v-if="suggestion.channels.length > 0" class="flex items-center -space-x-1.5">
                                            <ChannelAvatar
                                                v-for="channel in suggestion.channels.slice(0, STACK_LIMIT)"
                                                :key="channel.id"
                                                :platform="channel.platform"
                                                :src="channel.avatar_url"
                                                :name="channelName(channel)"
                                                :size="20"
                                                ring="popover"
                                                :badge="false"
                                                avatar-class="ring-2 ring-popover"
                                                :data-testid="`${id}-suggestion-channel-${channel.id}`"
                                            />
                                            <span
                                                v-if="suggestion.channels.length > STACK_LIMIT"
                                                class="relative flex h-5 min-w-5 shrink-0 items-center justify-center rounded-md bg-foreground px-1 text-[10px] font-semibold text-background tabular-nums ring-2 ring-popover"
                                            >
                                                +{{ suggestion.channels.length - STACK_LIMIT }}
                                            </span>
                                        </span>
                                    </span>
                                </button>
                            </TooltipTrigger>
                            <TooltipContent side="left" :side-offset="20" :data-testid="`${id}-suggestion-tooltip`">
                                <ul class="flex flex-col gap-1.5 py-0.5">
                                    <li v-if="suggestion.isUser" class="flex items-center gap-2">
                                        <IconUser class="size-4 shrink-0" />
                                        {{ $t('channels.timezone_select.yours') }}
                                    </li>
                                    <li v-if="suggestion.isBrowser" class="flex items-center gap-2">
                                        <IconMapPin class="size-4 shrink-0" />
                                        {{ $t('channels.timezone_select.browser') }}
                                    </li>
                                    <li
                                        v-for="channel in suggestion.channels.slice(0, TOOLTIP_LIMIT)"
                                        :key="channel.id"
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <ChannelAvatar
                                            :platform="channel.platform"
                                            :src="channel.avatar_url"
                                            :name="channelName(channel)"
                                            :size="28"
                                            ring="popover"
                                        />
                                        <span class="truncate">{{ channelName(channel) }}</span>
                                    </li>
                                    <li v-if="suggestion.channels.length > TOOLTIP_LIMIT" class="opacity-80">
                                        {{
                                            $t('channels.timezone_select.more', {
                                                count: String(suggestion.channels.length - TOOLTIP_LIMIT),
                                            })
                                        }}
                                    </li>
                                </ul>
                            </TooltipContent>
                        </Tooltip>
                    </div>

                    <div
                        v-if="visibleOptions.length > 0"
                        :class="visibleSuggestions.length > 0 ? 'mt-2 border-t border-border-strong pt-2' : ''"
                    >
                        <p class="px-2 py-1 text-xs text-muted-foreground">
                            {{ $t('channels.timezone_select.all') }}
                        </p>
                        <button
                            v-for="option in visibleOptions"
                            :key="option.value"
                            type="button"
                            :data-testid="optionTestId(option.value)"
                            class="flex min-h-8 w-full items-center gap-2 rounded-md px-2 py-1.5 text-start text-sm transition-control hover:bg-accent"
                            @click="choose(option.value)"
                        >
                            <IconCheck :class="cn('size-4 shrink-0', model === option.value ? 'opacity-100' : 'opacity-0')" />
                            <span class="truncate">
                                {{ option.label }}
                                <span class="text-xs text-muted-foreground">({{ option.offset }})</span>
                            </span>
                        </button>
                    </div>
                </div>
            </TooltipProvider>
        </PopoverContent>
    </Popover>
</template>
