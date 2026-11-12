<script setup lang="ts">
import { IconUsers } from '@tabler/icons-vue';
import type { SuggestionProps } from '@tiptap/suggestion';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { wTrans } from 'laravel-vue-i18n';
import { computed, ref, useAttrs, useId, watch } from 'vue';

import { plainTextExtensions } from '@/components/tiptap/editor';
import { NoteBody, noteBodyOf } from '@/components/tiptap/plugins/noteBody';
import { NoteKeys } from '@/components/tiptap/plugins/noteKeys';
import { Avatar } from '@/components/ui/avatar';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { blank, filled, isString } from '@/lib/helpers';
import { EVERYONE_ID } from '@/lib/noteMentions';
import { filterBySearch } from '@/lib/search';
import type { MentionedPerson, PostNoteMentionable } from '@/types/post-note';

/**
 * The note editor: typing `@` lists everyone on the post (`@all` in the
 * reader's language) and the teammates who can be mentioned; picking one
 * inserts a mention chip that keeps the person's id. The model is the body to
 * store, with mentions as markup (the `NoteBody` plugin). Enter submits and
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

/** A row of the `@` list: everyone on the post, or one teammate. */
type Option = { id: string; label: string } & (
    | { kind: 'everyone' }
    | { kind: 'person'; person: PostNoteMentionable }
);

const everyoneWord = wTrans('notes.mention_everyone.word');
const everyoneDescription = wTrans('notes.mention_everyone.description');
const unknownWord = wTrans('notes.mention_unknown');

/**
 * The name a mention chip shows for an id: everyone's word, or the person's
 * name. A person not known yet (a pasted mention before the people loaded)
 * asks for the people and reads as unknown until they arrive.
 */
const mentionLabel = (id: string): string => {
    if (id === EVERYONE_ID) {
        return everyoneWord.value;
    }

    const name = [...props.mentions, ...(props.people ?? [])].find(
        (person) => person.id === id,
    )?.name;

    if (!name && props.people === null) {
        emit('needPeople');
    }

    return name ?? unknownWord.value;
};

/** The open `@` search: what was typed after it and how to insert a pick. */
const search = ref<{
    query: string;
    insert: (mention: { id: string; label: string }) => void;
} | null>(null);
const highlighted = ref(0);

/** Everyone first, then every teammate but the writer. */
const options = computed((): Option[] => [
    { kind: 'everyone', id: EVERYONE_ID, label: everyoneWord.value },
    ...(props.people ?? [])
        .filter((person) => person.id !== props.currentUserId)
        .map(
            (person): Option => ({
                kind: 'person',
                id: person.id,
                label: person.name,
                person,
            }),
        ),
]);

/** The options the typed query finds, searched in memory (the people load once). */
const matches = computed(() =>
    filterBySearch(options.value, search.value?.query, (option) =>
        option.kind === 'everyone'
            ? [option.label, everyoneDescription.value]
            : [option.label],
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

const pick = ({ id, label }: Option) => {
    search.value?.insert({ id, label });
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

const editor = useEditor({
    extensions: [
        ...plainTextExtensions(() => props.placeholder),
        NoteBody.configure({
            label: mentionLabel,
            chipAttributes: {
                class: 'rounded-sm bg-primary-subtle px-0.5 font-medium text-foreground',
                'data-testid': 'note-input-mention',
            },
            suggestion: {
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
        NoteKeys.configure({
            onSubmit: () => emit('submit'),
            onCancel: () => emit('cancel'),
            submitBlocked: () => listOpen.value && filled(matches.value),
            cancelBlocked: () => filled(search.value),
        }),
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
            ...(listOpen.value && filled(matches.value)
                ? {
                      'aria-controls': listId,
                      'aria-activedescendant': optionId(highlighted.value),
                  }
                : {}),
        }),
    },
    onCreate: ({ editor: created }) => {
        created.commands.setNoteBody(body.value);
    },
    onUpdate: ({ editor: current }) => {
        body.value = noteBodyOf(current);
    },
});

/** The editable element's combobox attributes follow the list, which is not part of the document. */
watch([listOpen, highlighted, matches], () => {
    editor.value?.view.setProps({});
});

/** Once the people load, chips that could not be named yet take their names. */
watch(
    () => props.people,
    () => {
        const current = editor.value;

        if (!current) {
            return;
        }

        const { tr } = current.state;

        current.state.doc.descendants((node, position) => {
            if (node.type.name !== 'mention') {
                return;
            }

            const label = mentionLabel(node.attrs.id);

            if (label !== node.attrs.label) {
                tr.setNodeMarkup(position, undefined, { ...node.attrs, label });
            }
        });

        if (tr.docChanged) {
            current.view.dispatch(tr);
        }
    },
);

/** The parent replaced the body (reset after sending, another note to edit). */
watch(body, (stored) => {
    const current = editor.value;

    if (current && stored !== noteBodyOf(current)) {
        current.commands.setNoteBody(stored);
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
                    :key="option.id"
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
                            >@{{ option.label }}</span
                        >
                        <span class="whitespace-nowrap text-muted-foreground">{{
                            $t('notes.mention_everyone.description')
                        }}</span>
                    </template>
                    <template v-else>
                        <Avatar
                            :src="option.person.photo_url"
                            :name="option.label"
                            class="size-5 shrink-0 text-[10px]"
                        />
                        <span class="truncate">{{ option.label }}</span>
                    </template>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
