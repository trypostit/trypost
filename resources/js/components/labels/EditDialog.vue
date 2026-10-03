<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { watch } from 'vue';

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
import { update as labelsUpdate } from '@/routes/app/labels';

interface LabelType {
    id: string;
    name: string;
    color: string;
}

const props = defineProps<{
    label: LabelType | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const form = useForm({
    name: '',
    color: '',
});

watch(
    [() => props.label, open],
    ([label, isOpen]) => {
        if (label && isOpen) {
            form.name = label.name;
            form.color = label.color;
            form.clearErrors();
        }
    },
    { immediate: true },
);

const submit = () => {
    if (!props.label) return;
    form.put(labelsUpdate.url(props.label.id), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-testid="edit-label-dialog" class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('labels.edit.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('labels.edit.description') }}
                </DialogDescription>
            </DialogHeader>
            <form @submit.prevent="submit" class="space-y-6">
                <div class="space-y-2">
                    <Label for="edit-name">{{ $t('labels.edit.name') }}</Label>
                    <Input
                        id="edit-name"
                        v-model="form.name"
                        data-testid="edit-label-name"
                        :placeholder="trans('labels.edit.name_placeholder')"
                        :class="{ 'border-destructive': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="text-sm text-destructive-text">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="edit-color">{{
                        $t('labels.edit.color')
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
                        data-testid="cancel-edit-label"
                        @click="closeDialog"
                    >
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-testid="submit-edit-label"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? $t('labels.edit.submitting')
                                : $t('labels.edit.submit')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
