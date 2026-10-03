<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

import HexColorInput from '@/components/HexColorInput.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
            <form class="space-y-6" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="create-name">{{
                        $t('labels.create.name')
                    }}</Label>
                    <Input
                        id="create-name"
                        v-model="form.name"
                        data-testid="create-label-name"
                        :placeholder="trans('labels.create.name_placeholder')"
                        :class="{ 'border-destructive': form.errors.name }"
                    />
                    <p
                        v-if="form.errors.name"
                        class="text-sm text-destructive-text"
                    >
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="create-color">{{
                        $t('labels.create.color')
                    }}</Label>
                    <HexColorInput v-model="form.color" name="color" />
                    <p
                        v-if="form.errors.color"
                        class="text-sm text-destructive-text"
                    >
                        {{ form.errors.color }}
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        data-testid="cancel-create-label"
                        @click="closeDialog"
                    >
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-testid="submit-create-label"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? $t('labels.create.submitting')
                                : $t('labels.create.submit')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
