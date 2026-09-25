<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

import SignatureForm from '@/components/signatures/SignatureForm.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { store as signaturesStore } from '@/routes/app/signatures';

const open = defineModel<boolean>('open', { default: false });
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
    <Sheet :open="open" @update:open="handleOpenChange">
        <SheetContent
            data-testid="create-signature-sheet"
            class="w-full gap-0 sm:max-w-lg"
        >
            <SheetHeader class="border-b px-6 py-5 pr-12">
                <SheetTitle>{{ $t('signatures.create.title') }}</SheetTitle>
                <SheetDescription>{{
                    $t('signatures.create.description')
                }}</SheetDescription>
            </SheetHeader>
            <div class="flex min-h-0 flex-1 flex-col overflow-y-auto px-6 py-6">
                <SignatureForm
                    v-model:name="form.name"
                    v-model:content="form.content"
                    mode="create"
                    id-prefix="create-signature"
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
