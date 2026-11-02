<script setup lang="ts">
import { IconPlus, IconX } from '@tabler/icons-vue';
import type { AcceptableValue } from 'reka-ui';
import { computed, nextTick, ref, useId } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { characterCount } from '@/lib/characters';
import {
    editablePollOptions,
    joinPollDuration,
    splitPollDuration,
    X_POLL_DEFAULT_DURATION,
    X_POLL_MAX_DURATION,
    X_POLL_MAX_OPTIONS,
    X_POLL_MIN_OPTIONS,
    X_POLL_OPTION_MAX_LENGTH,
    type XPoll,
    type XPollDuration,
} from '@/lib/xPoll';

const props = withDefaults(
    defineProps<{
        modelValue: { options?: string[]; duration_minutes?: number };
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: XPoll];
    remove: [];
}>();

const id = useId();
const choiceInputs = ref<{ $el: HTMLInputElement }[]>([]);

const options = computed((): string[] =>
    editablePollOptions(props.modelValue.options),
);
const durationMinutes = computed(
    (): number => props.modelValue.duration_minutes ?? X_POLL_DEFAULT_DURATION,
);
const duration = computed(
    (): XPollDuration => splitPollDuration(durationMinutes.value),
);
const atLongestLength = computed(
    (): boolean => durationMinutes.value >= X_POLL_MAX_DURATION,
);

const lengthParts: {
    part: keyof XPollDuration;
    labelKey: string;
    values: number[];
}[] = [
    {
        part: 'days',
        labelKey: 'posts.form.x.poll.days',
        values: Array.from({ length: 8 }, (_, value) => value),
    },
    {
        part: 'hours',
        labelKey: 'posts.form.x.poll.hours',
        values: Array.from({ length: 24 }, (_, value) => value),
    },
    {
        part: 'minutes',
        labelKey: 'posts.form.x.poll.minutes',
        values: Array.from({ length: 60 }, (_, value) => value),
    },
];

const update = (patch: Partial<XPoll>): void => {
    emit('update:modelValue', {
        options: options.value,
        duration_minutes: durationMinutes.value,
        ...patch,
    });
};

const choiceId = (index: number): string => `${id}-choice-${index}`;

const choiceLabelKey = (index: number): string =>
    index < X_POLL_MIN_OPTIONS
        ? 'posts.form.x.poll.choice'
        : 'posts.form.x.poll.choice_optional';

const canAddAfter = (index: number): boolean =>
    index === options.value.length - 1 &&
    options.value.length < X_POLL_MAX_OPTIONS;

const setChoice = (index: number, value: string | number): void => {
    update({
        options: options.value.map((option, position) =>
            position === index ? String(value) : option,
        ),
    });
};

const addChoice = async (): Promise<void> => {
    if (options.value.length >= X_POLL_MAX_OPTIONS) return;

    const added = options.value.length;
    update({ options: [...options.value, ''] });
    await nextTick();
    choiceInputs.value[added]?.$el.focus();
};

const removeChoice = (index: number): void => {
    update({
        options: options.value.filter((_, position) => position !== index),
    });
};

const setLengthPart = (
    part: keyof XPollDuration,
    value: AcceptableValue,
): void => {
    update({
        duration_minutes: joinPollDuration({
            ...duration.value,
            [part]: Number(value),
        }),
    });
};

const removePoll = (): void => {
    emit('remove');
};
</script>

<template>
    <div
        class="mx-[9px] rounded-xl border border-border"
        data-testid="x-poll"
    >
        <div class="flex flex-col gap-3 p-3">
            <div
                v-for="(option, index) in options"
                :key="index"
                class="flex items-start gap-1"
            >
                <div class="relative min-w-0 flex-1">
                    <label
                        :for="choiceId(index)"
                        class="pointer-events-none absolute start-2.5 top-1.5 text-xs text-muted-foreground"
                        data-single-line
                        >{{
                            $t(choiceLabelKey(index), {
                                number: String(index + 1),
                            })
                        }}</label
                    >
                    <span
                        v-if="option"
                        class="pointer-events-none absolute end-2.5 top-1.5 text-xs tabular-nums"
                        :class="
                            characterCount(option) > X_POLL_OPTION_MAX_LENGTH
                                ? 'text-destructive-text'
                                : 'text-muted-foreground'
                        "
                        :data-testid="`x-poll-choice-${index}-count`"
                        >{{ characterCount(option) }} /
                        {{ X_POLL_OPTION_MAX_LENGTH }}</span
                    >
                    <Input
                        :id="choiceId(index)"
                        ref="choiceInputs"
                        :model-value="option"
                        class="h-13 px-2.5 pt-5 pb-1.5"
                        :disabled="disabled"
                        :data-testid="`x-poll-choice-${index}`"
                        @update:model-value="setChoice(index, $event)"
                    />
                </div>
                <div class="mt-2.5 flex w-[4.25rem] shrink-0 gap-1">
                    <Button
                        v-if="index >= X_POLL_MIN_OPTIONS"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground"
                        :aria-label="
                            $t('posts.form.x.poll.remove_choice', {
                                number: String(index + 1),
                            })
                        "
                        :disabled="disabled"
                        :data-testid="`x-poll-remove-choice-${index}`"
                        @click="removeChoice(index)"
                    >
                        <IconX />
                    </Button>
                    <Button
                        v-if="canAddAfter(index)"
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-primary-text"
                        :aria-label="$t('posts.form.x.poll.add_choice')"
                        :disabled="disabled"
                        data-testid="x-poll-add-choice"
                        @click="addChoice"
                    >
                        <IconPlus class="size-5" />
                    </Button>
                </div>
            </div>
        </div>
        <div class="border-t border-border p-3">
            <p
                class="mb-2 text-[13px] font-medium text-foreground"
                data-single-line
            >
                {{ $t('posts.form.x.poll.length') }}
            </p>
            <div class="grid grid-cols-3 gap-2">
                <div
                    v-for="lengthPart in lengthParts"
                    :key="lengthPart.part"
                    class="relative min-w-0"
                >
                    <label
                        :for="`${id}-${lengthPart.part}`"
                        class="pointer-events-none absolute start-2.5 top-1.5 z-10 text-xs text-muted-foreground"
                        data-single-line
                        >{{ $t(lengthPart.labelKey) }}</label
                    >
                    <Select
                        :model-value="String(duration[lengthPart.part])"
                        :disabled="
                            disabled ||
                            (atLongestLength && lengthPart.part !== 'days')
                        "
                        @update:model-value="
                            setLengthPart(lengthPart.part, $event)
                        "
                    >
                        <SelectTrigger
                            :id="`${id}-${lengthPart.part}`"
                            class="h-13 w-full items-end px-2.5 pt-5 pb-1.5 data-[size=default]:h-13"
                            :data-testid="`x-poll-${lengthPart.part}`"
                        >
                            <SelectValue>{{
                                duration[lengthPart.part]
                            }}</SelectValue>
                        </SelectTrigger>
                        <SelectContent class="max-h-64">
                            <SelectItem
                                v-for="value in lengthPart.values"
                                :key="value"
                                :value="String(value)"
                                :data-testid="`x-poll-${lengthPart.part}-${value}`"
                                >{{ value }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </div>
        <Button
            type="button"
            variant="ghost"
            class="h-11 w-full rounded-t-none rounded-b-xl border-t border-border text-destructive-text hover:text-destructive-text"
            :disabled="disabled"
            data-testid="x-poll-remove"
            @click="removePoll"
        >
            <span data-single-line>{{ $t('posts.form.x.poll.remove') }}</span>
        </Button>
    </div>
</template>
