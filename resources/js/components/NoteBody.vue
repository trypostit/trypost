<script setup lang="ts">
import { computed } from 'vue';

import PostNoteMention from '@/components/posts/editor/PostNoteMention.vue';
import { parseNoteBody } from '@/lib/noteMentions';
import type { MentionedPerson, PostNoteMentionable } from '@/types/post-note';

/**
 * A note's text, with each mention as `@Name` (from the note's `mentions`, by
 * id) and a card on hover.
 */
const props = defineProps<{
    body: string;
    mentions: MentionedPerson[];
    people: PostNoteMentionable[] | null;
}>();

const emit = defineEmits<{
    needPeople: [];
}>();

const segments = computed(() => parseNoteBody(props.body));

const mentionedName = (id: string) =>
    props.mentions.find((person) => person.id === id)?.name;

const requestPeople = () => {
    emit('needPeople');
};
</script>

<template>
    <p
        class="mt-0.5 text-sm break-words whitespace-pre-wrap"
        data-testid="note-body"
    >
        <template v-for="(segment, index) in segments" :key="index">
            <span
                v-if="segment.kind === 'everyone'"
                class="rounded-sm bg-primary-subtle px-0.5 font-medium text-foreground"
                data-testid="note-mention-everyone"
                >@{{ $t('notes.mention_everyone.word') }}</span
            >
            <PostNoteMention
                v-else-if="segment.kind === 'mention'"
                :name="
                    mentionedName(segment.userId) ?? $t('notes.mention_unknown')
                "
                :user-id="segment.userId"
                :people="people"
                @need-people="requestPeople"
            />
            <template v-else>{{ segment.text }}</template>
        </template>
    </p>
</template>
