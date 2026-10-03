<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import SignatureForm from '@/components/signatures/SignatureForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { update as signaturesUpdate } from '@/routes/app/signatures';

interface Signature {
    id: string;
    name: string;
    content: string;
}

const props = defineProps<{ signature: Signature | null }>();
const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const form = useForm({ name: '', content: '' });

watch(
    [() => props.signature, open],
    ([signature, isOpen]) => {
        if (signature && isOpen) {
            form.name = signature.name;
            form.content = signature.content;
            form.clearErrors();
        }
    },
    { immediate: true },
);

const submit = (): void => {
    if (!props.signature) return;
    form.put(signaturesUpdate.url(props.signature.id), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-testid="edit-signature-sheet" class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('signatures.edit.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('signatures.edit.description') }}
                </DialogDescription>
            </DialogHeader>
            <SignatureForm
                v-model:name="form.name"
                v-model:content="form.content"
                mode="edit"
                id-prefix="edit-signature"
                :errors="form.errors"
                :processing="form.processing"
                @submit="submit"
                @cancel="closeDialog"
            />
        </DialogContent>
    </Dialog>
</template>
