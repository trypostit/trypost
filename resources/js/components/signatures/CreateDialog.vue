<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import SignatureForm from '@/components/signatures/SignatureForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { store as signaturesStore } from '@/routes/app/signatures';

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const form = useForm({ name: '', content: '' });

const submit = (): void => {
    form.post(signaturesStore.url(), {
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
};

const handleOpenChange = (value: boolean): void => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
    open.value = value;
};
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogContent data-testid="create-signature-sheet" class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('signatures.create.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('signatures.create.description') }}
                </DialogDescription>
            </DialogHeader>
            <SignatureForm
                v-model:name="form.name"
                v-model:content="form.content"
                mode="create"
                id-prefix="create-signature"
                :errors="form.errors"
                :processing="form.processing"
                @submit="submit"
                @cancel="closeDialog"
            />
        </DialogContent>
    </Dialog>
</template>
