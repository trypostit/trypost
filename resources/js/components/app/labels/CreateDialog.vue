<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import LabelForm from '@/components/app/labels/LabelForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { store as labelsStore } from '@/routes/app/labels';
import type { FlashData } from '@/types';

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const emit = defineEmits<{
    created: [label: { id: string; name: string; color: string }];
}>();

const DEFAULT_COLOR = '#7c3aed';

const form = useForm({
    name: '',
    color: DEFAULT_COLOR,
});

const submit = () => {
    form.post(labelsStore.url(), {
        onFlash: (flash) => {
            const { createdLabel } = flash as FlashData;

            if (createdLabel) {
                emit('created', createdLabel);
            }
        },
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
};

const handleOpenChange = (value: boolean) => {
    if (value) {
        form.reset();
        form.color = DEFAULT_COLOR;
        form.clearErrors();
    }
    open.value = value;
};
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogContent data-testid="create-label-sheet" class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('labels.create.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('labels.create.description') }}
                </DialogDescription>
            </DialogHeader>
            <LabelForm
                v-model:name="form.name"
                v-model:color="form.color"
                id-prefix="create-label"
                :errors="form.errors"
                :processing="form.processing"
                @submit="submit"
                @cancel="closeDialog"
            />
        </DialogContent>
    </Dialog>
</template>
