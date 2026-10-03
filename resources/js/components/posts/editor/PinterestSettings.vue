<script setup lang="ts">
import { computed } from 'vue';

import InputError from '@/components/InputError.vue';
import PinterestBoardPicker from '@/components/posts/editor/PinterestBoardPicker.vue';
import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Input } from '@/components/ui/input';
import { usePageErrors } from '@/composables/usePageErrors';
import type { PinterestBoard } from '@/types';

interface SocialAccount {
    id: string;
    username: string;
}

interface Props {
    socialAccount: SocialAccount;
    boards: PinterestBoard[];
    boardsTruncated?: boolean;
    meta: Record<string, any>;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
    boardsTruncated: false,
});

const emit = defineEmits<{
    'update:meta': [value: Record<string, any>];
}>();

const initialBoards = computed(() => ({
    boards: props.boards,
    truncated: props.boardsTruncated,
}));

const boardId = computed<string | null>({
    get: () => (props.meta?.board_id as string | undefined) ?? null,
    set: (value) => emit('update:meta', { ...props.meta, board_id: value }),
});

const pinTitle = computed({
    get: () => (props.meta?.title as string | undefined) || '',
    set: (value: string) => {
        emit('update:meta', {
            ...props.meta,
            title: value.trim() === '' ? null : value,
        });
    },
});

const pinLink = computed({
    get: () => (props.meta?.link as string | undefined) || '',
    set: (value: string) => {
        emit('update:meta', {
            ...props.meta,
            link: value.trim() === '' ? null : value,
        });
    },
});

const errors = usePageErrors();
const fieldError = (field: string) =>
    computed<string | undefined>(
        () =>
            Object.entries(errors.value).find(([key]) =>
                key.endsWith(`.meta.${field}`),
            )?.[1],
    );
const titleError = fieldError('title');
const linkError = fieldError('link');
const storedBoardError = fieldError('board_id');
const boardError = computed(() =>
    props.meta?.board_id ? undefined : storedBoardError.value,
);

const ids = computed(() => ({
    title: `pinterest-title-${props.socialAccount.id}`,
    link: `pinterest-link-${props.socialAccount.id}`,
    board: `pinterest-board-${props.socialAccount.id}`,
}));
</script>

<template>
    <SettingsSection>
        <SettingsRow
            :label="$t('posts.form.pinterest.title')"
            :label-for="ids.title"
            align-top
        >
            <Input
                :id="ids.title"
                v-model="pinTitle"
                type="text"
                data-testid="pinterest-title"
                :placeholder="$t('posts.form.pinterest.title_placeholder')"
                :disabled="disabled"
                :aria-invalid="titleError ? true : undefined"
            />
            <InputError :message="titleError" />
        </SettingsRow>

        <SettingsRow
            :label="$t('posts.form.pinterest.link')"
            :label-for="ids.link"
            align-top
        >
            <Input
                :id="ids.link"
                v-model="pinLink"
                type="text"
                data-testid="pinterest-link"
                :placeholder="$t('posts.form.pinterest.link_placeholder')"
                :disabled="disabled"
                :aria-invalid="linkError ? true : undefined"
            />
            <InputError :message="linkError" />
        </SettingsRow>

        <SettingsRow
            :label="$t('posts.form.pinterest.pinning_to')"
            :label-for="ids.board"
            align-top
        >
            <PinterestBoardPicker
                v-model="boardId"
                :trigger-id="ids.board"
                :account-id="socialAccount.id"
                :username="socialAccount.username"
                :initial-boards="initialBoards"
                :disabled="disabled"
                :invalid="!!boardError"
            />
            <InputError :message="boardError" />
        </SettingsRow>
    </SettingsSection>
</template>
