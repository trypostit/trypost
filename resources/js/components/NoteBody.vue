<script setup lang="ts">
import PostNoteMention from '@/components/posts/editor/PostNoteMention.vue';
import type { PostNoteMentionable, PostNotePart } from '@/types/post-note';

/**
 * A note's text from its parts, with each mention as `@Name` and a card on
 * hover.
 */
defineProps<{
    parts: PostNotePart[];
    people: PostNoteMentionable[] | null;
}>();

const emit = defineEmits<{
    needPeople: [];
}>();

const requestPeople = () => {
    emit('needPeople');
};
</script>

<template>
    <p
        class="mt-0.5 text-sm break-words whitespace-pre-wrap"
        data-testid="note-body"
    >
        <template v-for="(part, index) in parts" :key="index">
            <span
                v-if="part.type === 'everyone'"
                class="rounded-sm bg-primary-subtle px-0.5 font-medium text-foreground"
                data-testid="note-mention-everyone"
                >@{{ $t('notes.mention_everyone.word') }}</span
            >
            <PostNoteMention
                v-else-if="part.type === 'mention'"
                :name="part.name ?? $t('notes.mention_unknown')"
                :user-id="part.id"
                :people="people"
                @need-people="requestPeople"
            />
            <template v-else>{{ part.text }}</template>
        </template>
    </p>
</template>
