<script setup lang="ts">
import { IconClock } from '@tabler/icons-vue';
import { computed, nextTick, ref, watch } from 'vue';

import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import date from '@/date';
import { cn } from '@/lib/utils';

const props = defineProps<{
    modelValue: string;
    id?: string;
    disabled?: boolean;
    invalid?: boolean;
    class?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

defineOptions({ inheritAttrs: false });

const pad = (value: number): string => String(value).padStart(2, '0');

const format = (time: string): string =>
    time ? date.formatClockTime(time) : '';

const text = ref(format(props.modelValue));

const placeholder = computed(() =>
    date.formatClockTime('00:00').replace(/\d+/g, '—'),
);
const isOpen = ref(false);
const highlighted = ref<string | null>(null);
const anchor = ref<HTMLElement | null>(null);
const list = ref<HTMLElement | null>(null);

watch(
    () => props.modelValue,
    (value) => {
        if (!isOpen.value) {
            text.value = format(value);
        }
    },
);

const options = computed(() =>
    Array.from({ length: 96 }, (_, index) => {
        const value = `${pad(Math.floor(index / 4))}:${pad((index % 4) * 15)}`;

        return { value, label: date.formatClockTime(value) };
    }),
);

/**
 * Read a typed time: the label of an option, or a loose clock such as "9",
 * "930", "17:15" or "5:15 pm".
 */
const parse = (value: string): string | null => {
    const normalized = value.trim().toLowerCase();
    const option = options.value.find(
        (candidate) => candidate.label.toLowerCase() === normalized,
    );
    if (option) return option.value;

    const match = normalized
        .replace(/\s+/g, '')
        .match(/^(\d{1,2})(?::?(\d{2}))?(?:([ap])\.?m?\.?)?$/);
    if (!match) return null;

    let hour = Number(match[1]);
    const minute = Number(match[2] ?? 0);
    const meridiem = match[3];
    if (minute > 59) return null;
    if (meridiem) {
        if (hour < 1 || hour > 12) return null;
        hour = (hour % 12) + (meridiem === 'p' ? 12 : 0);
    } else if (hour > 23) {
        return null;
    }

    return `${pad(hour)}:${pad(minute)}`;
};

const nearestOption = (time: string): string => {
    const [hour, minute] = (time || '09:00').split(':').map(Number);

    return `${pad(hour)}:${pad(Math.floor(minute / 15) * 15)}`;
};

const scrollToHighlighted = (): void => {
    nextTick(() => {
        const item = list.value?.querySelector<HTMLElement>(
            '[data-highlighted]',
        );
        if (!list.value || !item) return;
        list.value.scrollTop =
            item.offsetTop - list.value.clientHeight / 2 + item.clientHeight / 2;
    });
};

const open = (): void => {
    if (props.disabled) return;
    highlighted.value = nearestOption(props.modelValue);
    isOpen.value = true;
    scrollToHighlighted();
};

const onInput = (event: Event): void => {
    text.value = (event.target as HTMLInputElement).value;
    if (text.value.trim() === '') {
        emit('update:modelValue', '');
    } else {
        const parsed = parse(text.value);
        if (parsed) {
            emit('update:modelValue', parsed);
            highlighted.value = nearestOption(parsed);
            scrollToHighlighted();
        }
    }
    isOpen.value = true;
};

const commit = (): void => {
    const parsed = text.value.trim() === '' ? '' : parse(text.value);
    if (parsed !== null && parsed !== props.modelValue) {
        emit('update:modelValue', parsed);
    }
    text.value = format(parsed ?? props.modelValue);
    isOpen.value = false;
};

const select = (time: string): void => {
    emit('update:modelValue', time);
    text.value = format(time);
    isOpen.value = false;
};

const moveHighlight = (step: number): void => {
    if (!isOpen.value) {
        open();
        return;
    }
    const values = options.value.map((option) => option.value);
    const current = values.indexOf(
        highlighted.value ?? nearestOption(props.modelValue),
    );
    highlighted.value =
        values[Math.min(values.length - 1, Math.max(0, current + step))];
    scrollToHighlighted();
};

const onEnter = (): void => {
    if (isOpen.value && highlighted.value && !parse(text.value)) {
        select(highlighted.value);
        return;
    }
    commit();
};

const onEscape = (event: KeyboardEvent): void => {
    if (!isOpen.value) return;
    event.stopPropagation();
    commit();
};

const onInteractOutside = (event: Event): void => {
    if (anchor.value?.contains(event.target as Node)) {
        event.preventDefault();
    }
};
</script>

<template>
    <Popover :open="isOpen">
        <PopoverAnchor as-child>
            <div
                ref="anchor"
                :class="
                    cn(
                        'flex h-9 items-center gap-2 rounded-lg border border-border-strong bg-background px-2.5 transition-control focus-within:border-primary-strong focus-within:ring-1 focus-within:ring-primary-strong',
                        invalid && 'border-destructive',
                        disabled && 'cursor-not-allowed opacity-50',
                        props.class,
                    )
                "
            >
                <IconClock class="size-4 shrink-0 text-muted-foreground" />
                <input
                    :id="id"
                    v-bind="$attrs"
                    :value="text"
                    :placeholder="placeholder"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    role="combobox"
                    :aria-expanded="isOpen"
                    :aria-invalid="invalid ? true : undefined"
                    :disabled="disabled"
                    class="h-full min-w-0 flex-1 bg-transparent text-sm outline-none disabled:cursor-not-allowed"
                    @focus="open"
                    @click="isOpen || open()"
                    @input="onInput"
                    @blur="commit"
                    @keydown.enter.prevent="onEnter"
                    @keydown.down.prevent="moveHighlight(1)"
                    @keydown.up.prevent="moveHighlight(-1)"
                    @keydown.esc="onEscape"
                />
            </div>
        </PopoverAnchor>
        <PopoverContent
            align="start"
            class="w-(--reka-popover-trigger-width) min-w-32 p-0"
            @open-auto-focus.prevent
            @close-auto-focus.prevent
            @interact-outside="onInteractOutside"
            @escape-key-down="commit"
            @pointer-down-outside="onInteractOutside"
        >
            <div
                ref="list"
                role="listbox"
                class="max-h-64 overflow-y-auto p-1"
                data-testid="time-field-list"
            >
                <button
                    v-for="option in options"
                    :key="option.value"
                    type="button"
                    role="option"
                    tabindex="-1"
                    :aria-selected="option.value === modelValue"
                    :data-highlighted="
                        option.value === highlighted ? '' : undefined
                    "
                    :data-testid="`time-field-option-${option.value.replace(':', '')}`"
                    class="block w-full rounded-md px-3 py-2 text-start text-sm transition-control outline-none"
                    :class="
                        option.value === modelValue
                            ? 'bg-primary-subtle font-emphasis text-primary-text'
                            : option.value === highlighted
                              ? 'bg-accent text-foreground'
                              : 'text-foreground hover:bg-accent'
                    "
                    @mousedown.prevent
                    @click="select(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
