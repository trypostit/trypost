<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import SavedReplyForm from '@/components/saved-replies/SavedReplyForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { SavedReply } from '@/lib/savedReplies';
import {
    store as savedRepliesStore,
    update as savedRepliesUpdate,
} from '@/routes/app/saved-replies';

const props = defineProps<{ savedReply: SavedReply | null }>();
const open = defineModel<boolean>('open', { default: false });

const mode = computed<'create' | 'edit'>(() =>
    props.savedReply ? 'edit' : 'create',
);

const form = useForm({ name: '', content: '' });

watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        form.name = props.savedReply?.name ?? '';
        form.content = props.savedReply?.content ?? '';
        form.clearErrors();
    },
    { immediate: true },
);

const closeDialog = (): void => {
    open.value = false;
};

const submit = (): void => {
    const options = {
        preserveScroll: true,
        onSuccess: closeDialog,
    };

    if (props.savedReply) {
        form.put(savedRepliesUpdate.url(props.savedReply.id), options);

        return;
    }

    form.post(savedRepliesStore.url(), options);
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-testid="saved-reply-dialog" class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t(`saved_replies.${mode}.title`) }}</DialogTitle>
                <DialogDescription>
                    {{ $t(`saved_replies.${mode}.description`) }}
                </DialogDescription>
            </DialogHeader>
            <SavedReplyForm
                v-model:name="form.name"
                v-model:content="form.content"
                :mode="mode"
                id-prefix="saved-reply"
                :initial-name="savedReply?.name ?? ''"
                :initial-content="savedReply?.content ?? ''"
                :errors="form.errors"
                :processing="form.processing"
                @submit="submit"
                @cancel="closeDialog"
            />
        </DialogContent>
    </Dialog>
</template>
