<script setup lang="ts">
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

const props = defineProps<{
    title: string;
    label: string;
    submitLabel: string;
    initialName?: string;
    placeholder?: string;
    testid: string;
}>();

const emit = defineEmits<{
    close: [];
    save: [name: string];
}>();

const name = ref(props.initialName ?? '');

const trimmed = computed(() => name.value.trim());
const canSubmit = computed(() => trimmed.value.length > 0);

const submit = (): void => {
    if (canSubmit.value) {
        emit('save', trimmed.value);
    }
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};

const selectOnFocus = (event: FocusEvent): void => {
    (event.target as HTMLInputElement).select();
};
</script>

<template>
    <Dialog :open="true" @update:open="onOpenChange">
        <DialogContent
            class="sm:max-w-[420px]"
            :aria-describedby="undefined"
            :data-testid="testid"
        >
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
            </DialogHeader>

            <form
                :id="`${testid}-form`"
                class="space-y-1.5"
                novalidate
                @submit.prevent="submit"
            >
                <label :for="`${testid}-input`" class="text-sm font-medium">{{
                    label
                }}</label>
                <Input
                    :id="`${testid}-input`"
                    v-model="name"
                    type="text"
                    autocomplete="off"
                    autofocus
                    :placeholder="placeholder"
                    :data-testid="`${testid}-input`"
                    @focus="selectOnFocus"
                />
            </form>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    :data-testid="`${testid}-cancel`"
                    @click="emit('close')"
                >
                    {{ $t('create.feeds.cancel') }}
                </Button>
                <Button
                    type="submit"
                    :form="`${testid}-form`"
                    :disabled="!canSubmit"
                    :data-testid="`${testid}-submit`"
                >
                    {{ submitLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
