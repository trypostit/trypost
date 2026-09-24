<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { store as signaturesStore } from '@/routes/app/signatures';

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    content: '',
});

const submit = () => {
    form.post(signaturesStore.url(), {
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
};

const handleOpenChange = (value: boolean) => {
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
                <SheetDescription>
                    {{ $t('signatures.create.description') }}
                </SheetDescription>
            </SheetHeader>
            <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-6">
                    <div class="grid gap-2">
                        <Label for="create-name">{{
                            $t('signatures.create.name')
                        }}</Label>
                        <Input
                            id="create-name"
                            v-model="form.name"
                            data-testid="create-signature-name"
                            :placeholder="
                                trans('signatures.create.name_placeholder')
                            "
                            :class="{ 'border-destructive': form.errors.name }"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="create-content">{{
                            $t('signatures.create.content')
                        }}</Label>
                        <Textarea
                            id="create-content"
                            v-model="form.content"
                            data-testid="create-signature-content"
                            :placeholder="
                                trans('signatures.create.content_placeholder')
                            "
                            rows="4"
                            :class="{
                                'border-destructive': form.errors.content,
                            }"
                        />
                        <p class="text-sm text-muted-foreground">
                            {{ $t('signatures.create.content_hint') }}
                        </p>
                        <p
                            v-if="form.errors.content"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.content }}
                        </p>
                    </div>
                </div>

                <SheetFooter class="flex-row justify-end border-t px-6 py-4">
                    <Button
                        type="button"
                        variant="secondary"
                        data-testid="cancel-create-signature"
                        @click="open = false"
                    >
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-testid="submit-create-signature"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? $t('signatures.create.submitting')
                                : $t('signatures.create.submit')
                        }}
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
