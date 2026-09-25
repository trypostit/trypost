<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import SignatureForm from '@/components/signatures/SignatureForm.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { update as signaturesUpdate } from '@/routes/app/signatures';

interface Signature {
    id: string;
    name: string;
    content: string;
}

const props = defineProps<{ signature: Signature | null }>();
const open = defineModel<boolean>('open', { default: false });
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
    <Sheet v-model:open="open">
        <SheetContent
            data-testid="edit-signature-sheet"
            class="w-full gap-0 sm:max-w-lg"
        >
            <SheetHeader class="border-b px-6 py-5 pr-12">
                <SheetTitle>{{ $t('signatures.edit.title') }}</SheetTitle>
                <SheetDescription>{{
                    $t('signatures.edit.description')
                }}</SheetDescription>
            </SheetHeader>
            <div class="flex min-h-0 flex-1 flex-col overflow-y-auto px-6 py-6">
                <SignatureForm
                    v-model:name="form.name"
                    v-model:content="form.content"
                    mode="edit"
                    id-prefix="edit-signature"
                    :errors="form.errors"
                    :processing="form.processing"
                    class="flex-1"
                    @submit="submit"
                    @cancel="open = false"
                />
            </div>
        </SheetContent>
    </Sheet>
</template>
