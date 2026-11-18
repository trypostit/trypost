<script setup lang="ts">
import { IconPlus, IconTrash, IconX } from '@tabler/icons-vue';
import type { AcceptableValue } from 'reka-ui';
import { computed, nextTick, useId } from 'vue';

import InputError from '@/components/InputError.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useMetaErrors } from '@/composables/useMetaErrors';
import { filled } from '@/lib/helpers';
import {
    editablePollOptions,
    fitPollChoice,
    pollChoiceLength,
    pollDurationOf,
    pollHasDuration,
    HOURS_PER_DAY,
    joinPollDuration,
    MINUTES_PER_HOUR,
    pollMaxDays,
    splitPollDuration,
} from '@/lib/poll';
import type { Poll, PollDuration, PollLimits } from '@/types/poll';

const props = withDefaults(
    defineProps<{
        modelValue: Partial<Poll>;
        /** The account's poll limits, from its `publishConfig`. */
        limits: PollLimits;
        /** This card's position in the submitted `destinations`; see useMetaErrors. */
        platformIndex: number;
        /** Where the poll sits in the meta: '' for the post, `thread_replies.{n}.` for a reply. */
        fieldPrefix?: string;
        /** Prefix of every test id, so a post and its replies can each show a poll. */
        testIdPrefix?: string;
        disabled?: boolean;
    }>(),
    { fieldPrefix: '', testIdPrefix: 'poll', disabled: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: Poll];
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
/** Whether the author picks the length: not on Threads, which sets it. */
const hasDuration = computed((): boolean => pollHasDuration(props.limits));
const durationMinutes = computed((): number =>
    pollDurationOf(props.modelValue, props.limits),
);
const duration = computed(
    (): PollDuration => splitPollDuration(durationMinutes.value),
);
const atLongestLength = computed((): boolean => {
    const longest = props.limits.maxDuration;

    if (!filled(longest)) {
        return false;
    }

    return durationMinutes.value >= longest;
});
/** A length select is off at the longest length, where only the days count. */
const lengthPartDisabled = (part: keyof PollDuration): boolean => {
    if (props.disabled) {
        return true;
    }

    return atLongestLength.value && part !== 'days';
};
/** Mastodon lets the author pick multiple choice and hidden totals. */
const hasSwitches = computed((): boolean => {
    if (props.limits.multipleChoice) {
        return true;
    }

    return props.limits.hideTotals;
});
const multiple = computed((): boolean => props.modelValue.multiple === true);
const hideTotals = computed(
    (): boolean => props.modelValue.hide_totals === true,
);

const lengthParts: {
    part: keyof PollDuration;
    labelKey: string;
    values: number[];
}[] = [
    {
        part: 'days',
        labelKey: 'posts.form.poll.days',
        values: Array.from(
            { length: pollMaxDays(props.limits) + 1 },
            (_, value) => value,
        ),
    },
    {
        part: 'hours',
        labelKey: 'posts.form.poll.hours',
        values: Array.from({ length: HOURS_PER_DAY }, (_, value) => value),
    },
    {
        part: 'minutes',
        labelKey: 'posts.form.poll.minutes',
        values: Array.from({ length: MINUTES_PER_HOUR }, (_, value) => value),
    },
];

/**
 * The poll with the editor's choices and length. Every other key stays as it
 * came, in its order (MySQL reorders a stored poll's keys), and a switch is
 * only added once it is used, so a stored poll edited back to what it was is
 * no unsaved change.
 */
const currentPoll = (): Poll => {
    const poll: Poll = { ...props.modelValue, options: options.value };

    if (hasDuration.value) {
        poll.duration_minutes = durationMinutes.value;
    }

    return poll;
};

const update = (patch: Partial<Poll>): void => {
    emit('update:modelValue', { ...currentPoll(), ...patch });
};

const setMultiple = (value: boolean): void => {
    update({ multiple: value });
};

const setHideTotals = (value: boolean): void => {
    update({ hide_totals: value });
};

/** The `:number` a choice's labels show, counted from one. */
const choiceNumber = (index: number): { number: string } => ({
    number: String(index + 1),
});

const choiceLabelKey = (index: number): string => {
    if (index < props.limits.minOptions) {
        return 'posts.form.poll.choice';
    }

    return 'posts.form.poll.choice_optional';
};

/** A choice past the ones every poll needs can be removed. */
const isOptionalChoice = (index: number): boolean =>
    index >= props.limits.minOptions;

/** Another choice fits until the network's most. */
const canAddChoice = computed(
    (): boolean => options.value.length < props.limits.maxOptions,
);

/** A choice the server refused is marked invalid for assistive tech; none otherwise. */
const choiceInvalid = (index: number): true | undefined => {
    if (!filled(choiceErrors[index]?.value)) {
        return undefined;
    }

    return true;
};

/** A choice's counter turns red once it runs over the network's limit. */
const choiceCountClass = (option: string): string => {
    if (pollChoiceLength(option) > props.limits.optionMaxLength) {
        return 'text-destructive-text';
    }

    return 'text-muted-foreground';
};

const writeChoice = (index: number, choice: string): void => {
    update({
        options: options.value.map((option, position) =>
            position === index ? choice : option,
        ),
    });
};

/**
 * Keeps a choice within its limit while typing. When the cut choice is the one
 * already stored, the input (which keeps its own copy) would hold on to the
 * typed text, so the typed text goes in first and the cut one right after.
 */
const setChoice = async (
    index: number,
    value: string | number,
): Promise<void> => {
    const typed = String(value);
    const choice = fitPollChoice(typed, props.limits.optionMaxLength);

    if (choice !== typed && choice === options.value[index]) {
        writeChoice(index, typed);
        await nextTick();
    }

    writeChoice(index, choice);
};

const addChoice = async (): Promise<void> => {
    if (!canAddChoice.value) {
        return;
    }

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
    part: keyof PollDuration,
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
                    $t('posts.form.poll.choices')
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
                        :aria-invalid="choiceInvalid(index)"
                        :disabled="disabled"
                        :data-testid="`${testIdPrefix}-choice-${index}`"
                        @update:model-value="setChoice(index, $event)"
                    />
                    <span
                        class="w-11 shrink-0 text-end text-xs tabular-nums"
                        :class="choiceCountClass(option)"
                        :data-testid="`${testIdPrefix}-choice-${index}-count`"
                        >{{ pollChoiceLength(option) }} /
                        {{ limits.optionMaxLength }}</span
                    >
                    <Button
                        v-if="isOptionalChoice(index)"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="text-muted-foreground"
                        :aria-label="
                            $t(
                                'posts.form.poll.remove_choice',
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
                v-if="canAddChoice"
                type="button"
                variant="outline"
                size="sm"
                :disabled="disabled"
                :data-testid="`${testIdPrefix}-add-choice`"
                @click="addChoice"
            >
                <IconPlus class="size-4" />
                <span data-single-line>{{
                    $t('posts.form.poll.add_choice')
                }}</span>
            </Button>
        </SettingsRow>
        <SettingsRow v-if="hasDuration">
            <template #label>
                <span data-single-line>{{
                    $t('posts.form.poll.length')
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
                        :disabled="lengthPartDisabled(lengthPart.part)"
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
        <SettingsRow v-if="hasSwitches">
            <div class="flex flex-col gap-3">
                <div
                    v-if="limits.multipleChoice"
                    class="flex items-center gap-2"
                >
                    <Switch
                        :id="`${id}-multiple`"
                        :model-value="multiple"
                        :disabled="disabled"
                        :data-testid="`${testIdPrefix}-multiple`"
                        @update:model-value="setMultiple"
                    />
                    <Label :for="`${id}-multiple`" class="font-normal">{{
                        $t('posts.form.poll.multiple')
                    }}</Label>
                </div>
                <div v-if="limits.hideTotals" class="flex items-center gap-2">
                    <Switch
                        :id="`${id}-hide-totals`"
                        :model-value="hideTotals"
                        :disabled="disabled"
                        :data-testid="`${testIdPrefix}-hide-totals`"
                        @update:model-value="setHideTotals"
                    />
                    <Label :for="`${id}-hide-totals`" class="font-normal">{{
                        $t('posts.form.poll.hide_totals')
                    }}</Label>
                </div>
            </div>
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
                        $t('posts.form.poll.remove')
                    }}</span>
                </Button>
            </div>
        </SettingsRow>
    </div>
</template>
