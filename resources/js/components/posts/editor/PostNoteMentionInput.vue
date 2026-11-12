<script setup lang="ts">
import { computed, nextTick, ref, shallowRef, watch } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { filled } from '@/lib/helpers';
import {
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
 * A note textarea where typing `@` lists the teammates who can be mentioned;
 * picking one inserts `@Name`. The model is the body to store, where each
 * picked `@Name` becomes `@[Name](user:{id})`; the textarea only shows names.
 * Enter submits (Shift+Enter breaks the line); Esc closes the list, then
 * cancels. Attributes (class, placeholder, test id) go to the textarea.
 */
defineOptions({ inheritAttrs: false });

const MAX_LISTED = 8;

const props = defineProps<{
    people: PostNoteMentionable[] | null;
}>();

const emit = defineEmits<{
    submit: [];
    cancel: [];
    needPeople: [];
}>();

const body = defineModel<string>({ required: true });

const draft = ref(plainNoteBody(body.value));
const picked = shallowRef(mentionedPeople(body.value));
const textarea = ref<HTMLTextAreaElement | null>(null);
const query = ref<{ start: number; query: string } | null>(null);
const highlighted = ref(0);

const matches = computed(() =>
    filterBySearch(props.people ?? [], query.value?.query, (person) => [
        person.name,
    ]).slice(0, MAX_LISTED),
);

const listOpen = computed(() => query.value !== null && filled(props.people));

const syncModel = () => {
    body.value = withMentionMarkup(draft.value, picked.value);
};

watch(body, (stored) => {
    if (stored !== withMentionMarkup(draft.value, picked.value)) {
        draft.value = plainNoteBody(stored);
        picked.value = mentionedPeople(stored);
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

    if (!found || isPickedMentionAt(element.value, found.start, picked.value)) {
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

const pick = async (person: PostNoteMentionable) => {
    const element = textarea.value;
    const found = query.value;

    if (!element || !found) {
        return;
    }

    const result = insertMention(
        draft.value,
        found.start,
        element.selectionStart,
        person,
    );
    draft.value = result.text;

    if (!picked.value.some((known) => known.id === person.id)) {
        picked.value = [...picked.value, { id: person.id, name: person.name }];
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

        if (query.value !== null) {
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
                @input="onInput"
                @keydown="onKeydown"
                @click="updateQuery"
                @blur="closeList"
            />
        </PopoverAnchor>
        <PopoverContent
            side="top"
            align="start"
            class="w-64 p-1"
            data-testid="note-mention-list"
            @open-auto-focus.prevent
            @close-auto-focus.prevent
        >
            <p
                v-if="matches.length === 0"
                class="px-2 py-1.5 text-sm text-muted-foreground"
            >
                {{ $t('notes.no_mentionables') }}
            </p>
            <ul v-else role="listbox" class="flex flex-col">
                <li
                    v-for="(person, index) in matches"
                    :key="person.id"
                    role="option"
                    :aria-selected="index === highlighted"
                    class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                    :class="{ 'bg-accent': index === highlighted }"
                    data-testid="note-mention-option"
                    @mousedown.prevent="pick(person)"
                    @mouseenter="highlight(index)"
                >
                    <Avatar
                        :src="person.photo_url"
                        :name="person.name"
                        class="size-5 shrink-0 text-[10px]"
                    />
                    <span class="truncate">{{ person.name }}</span>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
