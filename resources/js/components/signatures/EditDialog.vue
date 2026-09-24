<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { watch } from 'vue';

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
import { Textarea } from '@/components/ui/textarea';
import { update as signaturesUpdate } from '@/routes/app/signatures';

interface Signature {
    id: string;
    name: string;
    content: string;
}

const props = defineProps<{
    signature: Signature | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    content: '',
});

watch(
    () => props.signature,
    (signature) => {
        if (signature) {
            form.name = signature.name;
            form.content = signature.content;
            form.clearErrors();
        }
    },
    { immediate: true },
);

const submit = () => {
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
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ $t('signatures.edit.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('signatures.edit.description') }}
                </DialogDescription>
            </DialogHeader>
            <form @submit.prevent="submit" class="space-y-4">
                <div class="grid gap-2">
                    <Label for="edit-name">{{
                        $t('signatures.edit.name')
                    }}</Label>
                    <Input
                        id="edit-name"
                        v-model="form.name"
                        :placeholder="trans('signatures.edit.name_placeholder')"
                        :class="{ 'border-destructive': form.errors.name }"
                    />
                    <p v-if="form.errors.name" class="text-sm text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="edit-content">{{
                        $t('signatures.edit.content')
                    }}</Label>
                    <Textarea
                        id="edit-content"
                        v-model="form.content"
                        :placeholder="
                            trans('signatures.edit.content_placeholder')
                        "
                        rows="4"
                        :class="{ 'border-destructive': form.errors.content }"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ $t('signatures.edit.content_hint') }}
                    </p>
                    <p
                        v-if="form.errors.content"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.content }}
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                    >
                        {{ $t('common.cancel') }}
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{
                            form.processing
                                ? $t('signatures.edit.submitting')
                                : $t('signatures.edit.submit')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
