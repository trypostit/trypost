<script setup lang="ts">
import { IconUsers } from '@tabler/icons-vue';
import { Extension } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import HardBreak from '@tiptap/extension-hard-break';
import Mention from '@tiptap/extension-mention';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import { Placeholder } from '@tiptap/extensions';
import type { SuggestionProps } from '@tiptap/suggestion';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { wTrans } from 'laravel-vue-i18n';
import { computed, ref, useAttrs, useId, watch } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { blank, filled, isString } from '@/lib/helpers';
import { docToNoteBody, EVERYONE_ID, noteBodyToDoc } from '@/lib/noteMentions';
import { filterBySearch } from '@/lib/search';
import type { MentionedPerson, PostNoteMentionable } from '@/types/post-note';

/**
 * The note editor: typing `@` lists everyone on the post (`@all` in the
 * reader's language) and the teammates who can be mentioned; picking one
 * inserts a mention chip that keeps the person's id. The model is the body to
 * store, with mentions as markup (`lib/noteMentions.ts`). Enter submits and
 * Shift+Enter breaks the line; Esc closes the list, then cancels. Attributes
 * (class, test id, aria label) go to the editable element.
 */
defineOptions({ inheritAttrs: false });

const MAX_LISTED = 8;

const props = withDefaults(
    defineProps<{
        people: PostNoteMentionable[] | null;
        currentUserId: string;
        mentions?: MentionedPerson[];
        placeholder?: string;
    }>(),
    {
        mentions: () => [],
        placeholder: '',
    },
);

const emit = defineEmits<{
    submit: [];
    cancel: [];
    needPeople: [];
}>();

const body = defineModel<string>({ required: true });

const attrs = useAttrs();

type Option =
    | { kind: 'everyone' }
    | { kind: 'person'; person: PostNoteMentionable };

const everyoneWord = wTrans('notes.mention_everyone.word');
const everyoneDescription = wTrans('notes.mention_everyone.description');
const unknownWord = wTrans('notes.mention_unknown');

/** The name a mention chip shows for an id: everyone's word, or the person's name. */
const mentionLabel = (id: string): string =>
    id === EVERYONE_ID
        ? everyoneWord.value
        : (props.mentions.find((person) => person.id === id)?.name ??
          props.people?.find((person) => person.id === id)?.name ??
          unknownWord.value);

/** The open `@` search: what was typed after it and how to insert a pick. */
const search = ref<{
    query: string;
    insert: (person: { id: string; label: string }) => void;
} | null>(null);
const highlighted = ref(0);

const matches = computed(() =>
    filterBySearch<Option>(
        [
            { kind: 'everyone' },
            ...(props.people ?? [])
                .filter((person) => person.id !== props.currentUserId)
                .map((person): Option => ({ kind: 'person', person })),
        ],
        search.value?.query,
        (option) =>
            option.kind === 'everyone'
                ? [everyoneWord.value, everyoneDescription.value]
                : [option.person.name],
    ).slice(0, MAX_LISTED),
);

/** Open while a search is on and the people are loaded, until a search with a space finds nobody. */
const listOpen = computed(
    () =>
        filled(search.value) &&
        filled(props.people) &&
        (filled(matches.value) || !search.value.query.includes(' ')),
);

const listId = useId();
const optionId = (index: number) => `${listId}-${index}`;

const optionKey = (option: Option) =>
    option.kind === 'everyone' ? EVERYONE_ID : option.person.id;

const pick = (option: Option) => {
    search.value?.insert(
        option.kind === 'everyone'
            ? { id: EVERYONE_ID, label: everyoneWord.value }
            : { id: option.person.id, label: option.person.name },
    );
};

/**
 * Where the cursor was when the list last saw it. The highlight follows the
 * mouse only once it moves: a list opening under a still cursor gets mouse
 * events too, and they must not take the highlight from the keyboard.
 */
let pointerAt: string | null = null;

watch(listOpen, () => {
    pointerAt = null;
});

const highlight = (event: MouseEvent, index: number) => {
    const at = `${event.clientX},${event.clientY}`;
    const moved = filled(pointerAt) && at !== pointerAt;

    pointerAt = at;

    if (moved) {
        highlighted.value = index;
    }
};

const moveHighlight = (step: number) => {
    const count = matches.value.length;

    highlighted.value = (highlighted.value + step + count) % count;
};

const openSearch = (suggestion: SuggestionProps) => {
    if (props.people === null) {
        emit('needPeople');
    }

    if (suggestion.query !== search.value?.query) {
        highlighted.value = 0;
    }

    search.value = { query: suggestion.query, insert: suggestion.command };
};

const closeSearch = () => {
    search.value = null;
    highlighted.value = 0;
};

/** Arrows, Enter and Tab drive the open list; anything else types. */
const onSearchKeydown = (event: KeyboardEvent): boolean => {
    if (!listOpen.value || blank(matches.value)) {
        return false;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        moveHighlight(event.key === 'ArrowDown' ? 1 : -1);

        return true;
    }

    if (event.key === 'Enter' || event.key === 'Tab') {
        pick(matches.value[highlighted.value]);

        return true;
    }

    return false;
};

/** Enter submits and Esc cancels, unless the `@` search is open. */
const NoteKeys = Extension.create({
    name: 'noteKeys',
    addKeyboardShortcuts: () => ({
        Enter: () => {
            if (listOpen.value) {
                return false;
            }

            emit('submit');

            return true;
        },
        Escape: () => {
            if (filled(search.value)) {
                return false;
            }

            emit('cancel');

            return true;
        },
    }),
});

const editor = useEditor({
    content: noteBodyToDoc(body.value, mentionLabel),
    extensions: [
        Document,
        Paragraph,
        Text,
        HardBreak,
        Placeholder.configure({ placeholder: () => props.placeholder }),
        Mention.configure({
            renderText: ({ node }) => `@${node.attrs.label}`,
            renderHTML: ({ node }) => [
                'span',
                {
                    class: 'rounded-sm bg-primary-subtle px-0.5 font-medium text-foreground',
                    'data-testid': 'note-input-mention',
                },
                `@${node.attrs.label}`,
            ],
            suggestion: {
                char: '@',
                allowSpaces: true,
                items: () => [],
                render: () => ({
                    onStart: openSearch,
                    onUpdate: openSearch,
                    onExit: closeSearch,
                    onKeyDown: ({ event }) => onSearchKeydown(event),
                }),
            },
        }),
        NoteKeys,
    ],
    editorProps: {
        attributes: () => ({
            ...Object.fromEntries(
                Object.entries(attrs).filter(([, value]) => isString(value)),
            ),
            class: [
                'whitespace-pre-wrap break-words outline-none',
                '[&_p.is-editor-empty:first-child]:before:pointer-events-none [&_p.is-editor-empty:first-child]:before:float-left [&_p.is-editor-empty:first-child]:before:h-0 [&_p.is-editor-empty:first-child]:before:text-muted-foreground [&_p.is-editor-empty:first-child]:before:content-[attr(data-placeholder)]',
                String(attrs.class ?? ''),
            ].join(' '),
            role: 'combobox',
            'aria-multiline': 'true',
            'aria-autocomplete': 'list',
            'aria-expanded': String(listOpen.value),
            ...(listOpen.value ? { 'aria-controls': listId } : {}),
            ...(listOpen.value && filled(matches.value)
                ? { 'aria-activedescendant': optionId(highlighted.value) }
                : {}),
        }),
    },
    onUpdate: ({ editor: current }) => {
        body.value = docToNoteBody(current.getJSON());
    },
});

/** The parent replaced the body (reset after sending, another note to edit). */
watch(body, (stored) => {
    const current = editor.value;

    if (current && stored !== docToNoteBody(current.getJSON())) {
        current.commands.setContent(noteBodyToDoc(stored, mentionLabel), {
            emitUpdate: false,
        });
    }
});

const focus = () => {
    editor.value?.commands.focus('end');
};

defineExpose({ focus });
</script>

<template>
    <Popover :open="listOpen">
        <PopoverAnchor as-child>
            <EditorContent :editor="editor" class="min-w-0 flex-1" />
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
                    @mousemove="highlight($event, index)"
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
