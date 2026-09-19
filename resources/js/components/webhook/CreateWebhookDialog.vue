<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
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
import { store } from '@/routes/app/webhooks';

import WebhookFormFields from './WebhookFormFields.vue';

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    endpoint: '',
    events: [] as string[],
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

const submit = () => {
    form.post(store.url(), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ $t('webhooks.create.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('webhooks.create.description') }}
                </DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="submit">
                <WebhookFormFields
                    v-model:endpoint="form.endpoint"
                    v-model:events="form.events"
                    endpoint-id="create-endpoint"
                    endpoint-test-id="create-webhook-endpoint"
                    events-test-id="create-webhook-events"
                    :errors="form.errors"
                />

                <DialogFooter>
                    <Button
                        type="submit"
                        data-testid="create-webhook-submit"
                        :disabled="form.processing || form.events.length === 0"
                    >
                        {{ $t('webhooks.create.submit') }}
                    </Button>
                    <Button
                        variant="secondary"
                        type="button"
                        data-testid="cancel-create-webhook"
                        @click="open = false"
                    >
                        {{ $t('webhooks.create.cancel') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
