<script setup lang="ts">
import { IconPlus, IconTrash, IconX } from '@tabler/icons-vue';
import type { AcceptableValue } from 'reka-ui';
import { computed, nextTick, useId } from 'vue';

import InputError from '@/components/InputError.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useMetaErrors } from '@/composables/useMetaErrors';
import { characterCount } from '@/lib/characters';
import {
    editablePollOptions,
    HOURS_PER_DAY,
    joinPollDuration,
    MINUTES_PER_HOUR,
    pollMaxDays,
    splitPollDuration,
    type XPoll,
    type XPollDuration,
    type XPollLimits,
} from '@/lib/xPoll';

const props = withDefaults(
    defineProps<{
        modelValue: Partial<XPoll>;
        /** The account's poll limits, from its `publishConfig`. */
        limits: XPollLimits;
        /** This card's position in the submitted `destinations`; see useMetaErrors. */
        platformIndex: number;
        /** Where the poll sits in the meta: '' for the post, `thread_replies.{n}.` for a reply. */
        fieldPrefix?: string;
        /** Prefix of every test id, so a post and its replies can each show a poll. */
        testIdPrefix?: string;
        disabled?: boolean;
    }>(),
    { fieldPrefix: '', testIdPrefix: 'x-poll', disabled: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: XPoll];
    remove: [];
}>();

const id = useId();

const metaError = useMetaErrors(() => props.platformIndex);
const optionsError = metaError(`${props.fieldPrefix}poll.options`);
const durationError = metaError(`${props.fieldPrefix}poll.duration_minutes`);
const choiceErrors = Array.from(
    { length: props.limits.maxOptions },
    (_, index) => metaError(`${props.fieldPrefix}poll.options.${index}`),
);

const choiceId = (index: number): string => `${id}-choice-${index}`;

const options = computed((): string[] =>
    editablePollOptions(props.modelValue.options, props.limits.minOptions),
);
const durationMinutes = computed(
    (): number =>
        props.modelValue.duration_minutes ?? props.limits.defaultDuration,
);
const duration = computed(
    (): XPollDuration => splitPollDuration(durationMinutes.value),
);
const atLongestLength = computed(
    (): boolean => durationMinutes.value >= props.limits.maxDuration,
);

const lengthParts: {
    part: keyof XPollDuration;
    labelKey: string;
    values: number[];
}[] = [
    {
        part: 'days',
        labelKey: 'posts.form.x.poll.days',
        values: Array.from(
            { length: pollMaxDays(props.limits) + 1 },
            (_, value) => value,
        ),
    },
    {
        part: 'hours',
        labelKey: 'posts.form.x.poll.hours',
        values: Array.from({ length: HOURS_PER_DAY }, (_, value) => value),
    },
    {
        part: 'minutes',
        labelKey: 'posts.form.x.poll.minutes',
        values: Array.from({ length: MINUTES_PER_HOUR }, (_, value) => value),
    },
];

const update = (patch: Partial<XPoll>): void => {
    emit('update:modelValue', {
        options: options.value,
        duration_minutes: durationMinutes.value,
        ...patch,
    });
};

/** A choice's length as the server measures it, without the spaces around it. */
const choiceLength = (option: string): number => characterCount(option.trim());

/** The `:number` a choice's labels show, counted from one. */
const choiceNumber = (index: number): { number: string } => ({
    number: String(index + 1),
});

const choiceLabelKey = (index: number): string =>
    index < props.limits.minOptions
        ? 'posts.form.x.poll.choice'
        : 'posts.form.x.poll.choice_optional';

const setChoice = (index: number, value: string | number): void => {
    update({
        options: options.value.map((option, position) =>
            position === index ? String(value) : option,
        ),
    });
};

const addChoice = async (): Promise<void> => {
    if (options.value.length >= props.limits.maxOptions) return;

    const added = options.value.length;
    update({ options: [...options.value, ''] });
    await nextTick();
    document.getElementById(choiceId(added))?.focus();
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
        duration_minutes: joinPollDuration(
            { ...duration.value, [part]: Number(value) },
            props.limits,
        ),
    });
};

const removePoll = (): void => {
    emit('remove');
};
</script>

<template>
    <div class="mt-3 space-y-4" :data-testid="testIdPrefix">
        <SettingsRow align-top>
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.x.poll.choices')
                }}</span>
            </template>
            <template v-for="(option, index) in options" :key="index">
                <div class="flex items-center gap-2">
                    <Input
                        :id="choiceId(index)"
                        :model-value="option"
                        class="min-w-0 flex-1"
                        :aria-label="
                            $t(choiceLabelKey(index), choiceNumber(index))
                        "
                        :placeholder="
                            $t(choiceLabelKey(index), choiceNumber(index))
                        "
                        :aria-invalid="
                            choiceErrors[index]?.value ? true : undefined
                        "
                        :disabled="disabled"
                        :data-testid="`${testIdPrefix}-choice-${index}`"
                        @update:model-value="setChoice(index, $event)"
                    />
                    <span
                        class="w-11 shrink-0 text-end text-xs tabular-nums"
                        :class="
                            choiceLength(option) > limits.optionMaxLength
                                ? 'text-destructive-text'
                                : 'text-muted-foreground'
                        "
                        :data-testid="`${testIdPrefix}-choice-${index}-count`"
                        >{{ choiceLength(option) }} /
                        {{ limits.optionMaxLength }}</span
                    >
                    <Button
                        v-if="index >= limits.minOptions"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground"
                        :aria-label="
                            $t(
                                'posts.form.x.poll.remove_choice',
                                choiceNumber(index),
                            )
                        "
                        :disabled="disabled"
                        :data-testid="`${testIdPrefix}-remove-choice-${index}`"
                        @click="removeChoice(index)"
                    >
                        <IconX class="size-4" />
                    </Button>
                    <span v-else aria-hidden="true" class="size-7 shrink-0" />
                </div>
                <InputError
                    :message="choiceErrors[index]?.value"
                    :data-testid="`${testIdPrefix}-choice-${index}-error`"
                />
            </template>
            <InputError
                :message="optionsError"
                :data-testid="`${testIdPrefix}-options-error`"
            />
            <Button
                v-if="options.length < limits.maxOptions"
                type="button"
                variant="outline"
                size="sm"
                :disabled="disabled"
                :data-testid="`${testIdPrefix}-add-choice`"
                @click="addChoice"
            >
                <IconPlus class="size-4" />
                <span data-single-line>{{
                    $t('posts.form.x.poll.add_choice')
                }}</span>
            </Button>
        </SettingsRow>
        <SettingsRow>
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.x.poll.length')
                }}</span>
            </template>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div
                    v-for="lengthPart in lengthParts"
                    :key="lengthPart.part"
                    class="flex items-center gap-1.5"
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
                            class="w-16"
                            :data-testid="`${testIdPrefix}-${lengthPart.part}`"
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
                                :data-testid="`${testIdPrefix}-${lengthPart.part}-${value}`"
                                >{{ value }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <label
                        :for="`${id}-${lengthPart.part}`"
                        class="text-sm text-muted-foreground"
                        data-single-line
                        >{{ $t(lengthPart.labelKey) }}</label
                    >
                </div>
            </div>
            <InputError
                :message="durationError"
                :data-testid="`${testIdPrefix}-duration-error`"
            />
        </SettingsRow>
        <SettingsRow>
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="text-destructive-text hover:text-destructive-text"
                    :disabled="disabled"
                    :data-testid="`${testIdPrefix}-remove`"
                    @click="removePoll"
                >
                    <IconTrash class="size-4" />
                    <span data-single-line>{{
                        $t('posts.form.x.poll.remove')
                    }}</span>
                </Button>
            </div>
        </SettingsRow>
    </div>
</template>
