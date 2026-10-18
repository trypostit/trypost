<script setup lang="ts">
import { computed, nextTick, useTemplateRef } from 'vue';

import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { characterCount } from '@/lib/characters';
import {
    SAVED_REPLY_CONTENT_MAX_LENGTH,
    SAVED_REPLY_NAME_MAX_LENGTH,
    SAVED_REPLY_VARIABLES,
    type SavedReplyVariable,
    insertAtSelection,
} from '@/lib/savedReplies';

const props = withDefaults(
    defineProps<{
        mode: 'create' | 'edit';
        errors?: Partial<Record<'name' | 'content', string>>;
        processing?: boolean;
        idPrefix: string;
        compact?: boolean;
        initialName?: string;
        initialContent?: string;
    }>(),
    {
        errors: () => ({}),
        processing: false,
        compact: false,
        initialName: '',
        initialContent: '',
    },
);

const name = defineModel<string>('name', { required: true });
const content = defineModel<string>('content', { required: true });

const emit = defineEmits<{
    submit: [];
    cancel: [];
}>();

const contentInput = useTemplateRef<HTMLTextAreaElement>('contentInput');

const nameLength = computed(() => characterCount(name.value));
const contentLength = computed(() => characterCount(content.value));
const isNameTooLong = computed(() => nameLength.value > SAVED_REPLY_NAME_MAX_LENGTH);
const isContentTooLong = computed(
    () => contentLength.value > SAVED_REPLY_CONTENT_MAX_LENGTH,
);
const hasChanged = computed(
    () =>
        props.mode === 'create' ||
        name.value !== props.initialName ||
        content.value !== props.initialContent,
);
const canSave = computed(
    () =>
        name.value.trim() !== '' &&
        content.value.trim() !== '' &&
        !isNameTooLong.value &&
        !isContentTooLong.value &&
        hasChanged.value,
);

const submit = (): void => {
    if (!canSave.value || props.processing) {
        return;
    }

    emit('submit');
};

const cancel = (): void => {
    emit('cancel');
};

const tokenOf = (variable: SavedReplyVariable): string => `{${variable}}`;

const insertVariable = (variable: SavedReplyVariable): void => {
    const element = contentInput.value;
    const result = insertAtSelection(content.value, tokenOf(variable), element);

    content.value = result.text;

    nextTick(() => {
        element?.focus();
        element?.setSelectionRange(result.caret, result.caret);
    });
};
</script>

<template>
    <form
        class="flex min-h-0 flex-col"
        :data-testid="`${idPrefix}-form`"
        @submit.prevent="submit"
    >
        <div :class="compact ? 'space-y-3' : 'space-y-4'">
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-name`">{{
                    $t('saved_replies.form.name')
                }}</Label>
                <Input
                    :id="`${idPrefix}-name`"
                    v-model="name"
                    :data-testid="`${idPrefix}-name`"
                    :placeholder="$t('saved_replies.form.name_placeholder')"
                    :aria-invalid="Boolean(errors.name) || isNameTooLong"
                />
                <p
                    v-if="errors.name"
                    class="text-sm text-destructive-text"
                    :data-testid="`${idPrefix}-name-error`"
                >
                    {{ errors.name }}
                </p>
            </div>
            <div class="grid gap-2">
                <div class="flex items-center justify-between gap-2">
                    <Label :for="`${idPrefix}-content`">{{
                        $t('saved_replies.form.content')
                    }}</Label>
                    <span
                        class="text-xs tabular-nums"
                        :class="
                            isContentTooLong
                                ? 'text-destructive-text'
                                : 'text-muted-foreground'
                        "
                        :data-testid="`${idPrefix}-counter`"
                        >{{ contentLength }}/{{
                            SAVED_REPLY_CONTENT_MAX_LENGTH
                        }}</span
                    >
                </div>
                <textarea
                    :id="`${idPrefix}-content`"
                    ref="contentInput"
                    v-model="content"
                    data-slot="textarea"
                    :rows="compact ? 4 : 6"
                    class="flex max-h-72 w-full resize-none rounded-md border border-input bg-card px-2 py-1.5 text-sm transition-[color,box-shadow] outline-none [field-sizing:content] placeholder:text-muted-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring aria-invalid:border-destructive max-md:text-base"
                    :class="compact ? 'min-h-24' : 'min-h-32'"
                    :data-testid="`${idPrefix}-content`"
                    :placeholder="$t('saved_replies.form.content_placeholder')"
                    :aria-invalid="Boolean(errors.content) || isContentTooLong"
                    :aria-describedby="`${idPrefix}-variables`"
                />
                <p
                    v-if="errors.content"
                    class="text-sm text-destructive-text"
                    :data-testid="`${idPrefix}-content-error`"
                >
                    {{ errors.content }}
                </p>
                <div
                    :id="`${idPrefix}-variables`"
                    class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                    :data-testid="`${idPrefix}-variables`"
                >
                    <span>{{ $t('saved_replies.form.variables') }}</span>
                    <button
                        v-for="variable in SAVED_REPLY_VARIABLES"
                        :key="variable"
                        type="button"
                        class="rounded-md border border-border bg-muted px-1.5 py-0.5 font-mono text-[11px] text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        :title="$t(`saved_replies.form.variable_titles.${variable}`)"
                        :data-testid="`${idPrefix}-variable-${variable}`"
                        @click="insertVariable(variable)"
                    >
                        {{ tokenOf(variable) }}
                    </button>
                </div>
            </div>
        </div>
        <component
            :is="compact ? 'div' : DialogFooter"
            :class="compact ? 'mt-4 flex justify-end gap-2' : 'mt-6'"
        >
            <Button
                type="button"
                variant="ghost"
                :data-testid="`cancel-${idPrefix}`"
                @click="cancel"
            >
                {{ $t('common.cancel') }}
            </Button>
            <Button
                type="submit"
                :data-testid="`submit-${idPrefix}`"
                :loading="processing"
                :disabled="!canSave || processing"
            >
                {{ $t(`saved_replies.${mode}.submit`) }}
            </Button>
        </component>
    </form>
</template>
