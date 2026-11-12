<script setup lang="ts">
import { IconUsers } from '@tabler/icons-vue';
import { wTrans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, shallowRef, useId, watch } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { blank, filled } from '@/lib/helpers';
import type { MentionedPerson } from '@/lib/noteMentions';
import {
    EVERYONE_ID,
    insertMention,
    isPickedMentionAt,
    mentionedPeople,
    mentionQueryAt,
    plainNoteBody,
    withMentionMarkup,
} from '@/lib/noteMentions';
import { filterBySearch } from '@/lib/search';
import type { PostNoteMentionable } from '@/types/post-note';

/**
 * A note textarea where typing `@` lists the teammates who can be mentioned,
 * after an option for everyone on the post (`@all` in the reader's language);
 * picking one inserts `@Name`. The model is the body to store, where each
 * picked `@Name` becomes `@[Name](user:{id})`; the textarea only shows names.
 * Enter submits (Shift+Enter breaks the line); Esc closes the list, then
 * cancels. The list leaves out the viewer: nobody mentions themselves.
 * Attributes (class, placeholder, test id) go to the textarea.
 */
defineOptions({ inheritAttrs: false });

const MAX_LISTED = 8;

const props = defineProps<{
    people: PostNoteMentionable[] | null;
    currentUserId: string;
}>();

const emit = defineEmits<{
    submit: [];
    cancel: [];
    needPeople: [];
}>();

const body = defineModel<string>({ required: true });

type Option =
    | { kind: 'everyone' }
    | { kind: 'person'; person: PostNoteMentionable };

const everyoneWord = wTrans('notes.mention_everyone.word');
const everyoneDescription = wTrans('notes.mention_everyone.description');

const draft = ref(plainNoteBody(body.value, everyoneWord.value));
const picked = shallowRef(mentionedPeople(body.value, everyoneWord.value));
const textarea = ref<HTMLTextAreaElement | null>(null);
const query = ref<{ start: number; query: string } | null>(null);
const highlighted = ref(0);

const matches = computed(() =>
    filterBySearch<Option>(
        [
            { kind: 'everyone' },
            ...(props.people ?? [])
                .filter((person) => person.id !== props.currentUserId)
                .map((person): Option => ({ kind: 'person', person })),
        ],
        query.value?.query,
        (option) =>
            option.kind === 'everyone'
                ? [everyoneWord.value, everyoneDescription.value]
                : [option.person.name],
    ).slice(0, MAX_LISTED),
);

const optionKey = (option: Option) =>
    option.kind === 'everyone' ? EVERYONE_ID : option.person.id;

const listOpen = computed(() => filled(query.value) && filled(props.people));

const listId = useId();
const optionId = (index: number) => `${listId}-${index}`;
const activeOptionId = computed(() =>
    listOpen.value && filled(matches.value)
        ? optionId(highlighted.value)
        : undefined,
);

const syncModel = () => {
    body.value = withMentionMarkup(draft.value, picked.value);
};

watch(body, (stored) => {
    if (stored !== withMentionMarkup(draft.value, picked.value)) {
        draft.value = plainNoteBody(stored, everyoneWord.value);
        picked.value = mentionedPeople(stored, everyoneWord.value);
    }
});

const closeList = () => {
    query.value = null;
    highlighted.value = 0;
};

const updateQuery = () => {
    const element = textarea.value;

    if (!element) {
        return;
    }

    const found = mentionQueryAt(element.value, element.selectionStart);

    if (
        !filled(found) ||
        isPickedMentionAt(element.value, found.start, picked.value)
    ) {
        closeList();

        return;
    }

    if (props.people === null) {
        emit('needPeople');
    }

    query.value = found;
    highlighted.value = 0;
};

const onInput = (event: Event) => {
    draft.value = (event.target as HTMLTextAreaElement).value;
    syncModel();
    updateQuery();
};

const pick = async (option: Option) => {
    const element = textarea.value;
    const found = query.value;

    if (!element || !found) {
        return;
    }

    const person: MentionedPerson =
        option.kind === 'everyone'
            ? { id: EVERYONE_ID, name: everyoneWord.value }
            : { id: option.person.id, name: option.person.name };

    const result = insertMention(
        draft.value,
        found.start,
        element.selectionStart,
        person,
    );
    draft.value = result.text;

    if (!picked.value.some((known) => known.id === person.id)) {
        picked.value = [...picked.value, person];
    }

    syncModel();
    closeList();

    await nextTick();
    element.focus();
    element.setSelectionRange(result.caret, result.caret);
};

const highlight = (index: number) => {
    highlighted.value = index;
};

const moveHighlight = (step: number) => {
    const count = matches.value.length;

    highlighted.value = (highlighted.value + step + count) % count;
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.isComposing) {
        return;
    }

    if (listOpen.value && filled(matches.value)) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            moveHighlight(event.key === 'ArrowDown' ? 1 : -1);

            return;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            void pick(matches.value[highlighted.value]);

            return;
        }
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();

        if (filled(query.value)) {
            closeList();
        } else {
            emit('cancel');
        }

        return;
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        emit('submit');
    }
};

const focus = () => {
    textarea.value?.focus();
};

defineExpose({ focus });
</script>

<template>
    <Popover :open="listOpen">
        <PopoverAnchor as-child>
            <textarea
                ref="textarea"
                v-bind="$attrs"
                :value="draft"
                rows="1"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="listOpen"
                :aria-controls="listOpen ? listId : undefined"
                :aria-activedescendant="activeOptionId"
                @input="onInput"
                @keydown="onKeydown"
                @click="updateQuery"
                @blur="closeList"
            />
        </PopoverAnchor>
        <PopoverContent
            side="top"
            align="start"
            class="w-max max-w-[calc(100vw-2rem)] min-w-64 p-1"
            data-testid="note-mention-list"
            @open-auto-focus.prevent
            @close-auto-focus.prevent
        >
            <p
                v-if="blank(matches)"
                class="px-2 py-1.5 text-sm text-muted-foreground"
            >
                {{ $t('notes.no_mentionables') }}
            </p>
            <ul v-else :id="listId" role="listbox" class="flex flex-col">
                <li
                    v-for="(option, index) in matches"
                    :id="optionId(index)"
                    :key="optionKey(option)"
                    role="option"
                    :aria-selected="index === highlighted"
                    class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                    :class="{ 'bg-accent': index === highlighted }"
                    :data-testid="
                        option.kind === 'everyone'
                            ? 'note-mention-option-everyone'
                            : 'note-mention-option'
                    "
                    @mousedown.prevent="pick(option)"
                    @mouseenter="highlight(index)"
                >
                    <template v-if="option.kind === 'everyone'">
                        <span
                            class="grid size-5 shrink-0 place-items-center rounded-full bg-primary-strong text-primary-strong-foreground"
                        >
                            <IconUsers class="size-3" />
                        </span>
                        <span class="font-medium whitespace-nowrap"
                            >@{{ $t('notes.mention_everyone.word') }}</span
                        >
                        <span class="whitespace-nowrap text-muted-foreground">{{
                            $t('notes.mention_everyone.description')
                        }}</span>
                    </template>
                    <template v-else>
                        <Avatar
                            :src="option.person.photo_url"
                            :name="option.person.name"
                            class="size-5 shrink-0 text-[10px]"
                        />
                        <span class="truncate">{{ option.person.name }}</span>
                    </template>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
