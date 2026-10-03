<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { store } from '@/routes/app/webhooks';

import WebhookFormFields from './WebhookFormFields.vue';

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const form = useForm({
    endpoint: '',
    events: [] as string[],
});

const canSubmit = computed(
    () => form.endpoint.trim() !== '' && form.events.length > 0,
);

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

const submit = (): void => {
    if (!canSubmit.value || form.processing) {
        return;
    }

    form.post(store.url(), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            data-testid="create-webhook-dialog"
            class="gap-0 p-0 sm:max-w-lg"
        >
            <form @submit.prevent="submit">
                <div class="flex flex-col gap-1.5 px-8 pt-7 pb-5">
                    <DialogTitle class="font-sans text-base font-emphasis">
                        {{ $t('webhooks.create.title') }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        {{ $t('webhooks.create.description') }}
                    </DialogDescription>
                </div>

                <div class="px-8 pb-6">
                    <WebhookFormFields
                        v-model:endpoint="form.endpoint"
                        v-model:events="form.events"
                        endpoint-id="create-endpoint"
                        endpoint-test-id="create-webhook-endpoint"
                        events-test-id="create-webhook-events"
                        :errors="form.errors"
                    />
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-border px-8 py-4"
                >
                    <Button
                        variant="ghost"
                        type="button"
                        data-testid="cancel-create-webhook"
                        @click="closeDialog"
                    >
                        {{ $t('webhooks.create.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        data-testid="create-webhook-submit"
                        :loading="form.processing"
                        :disabled="!canSubmit || form.processing"
                    >
                        {{ $t('webhooks.create.submit') }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
