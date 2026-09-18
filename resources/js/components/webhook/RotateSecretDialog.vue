<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { rotateSecret } from '@/routes/app/webhooks';

const props = defineProps<{
    webhookId: string;
}>();

const open = defineModel<boolean>('open', { default: false });
const rotating = ref(false);

const handleRotate = () => {
    rotating.value = true;
    router.post(
        rotateSecret.url(props.webhookId),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                rotating.value = false;
                open.value = false;
            },
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ $t('webhooks.rotate.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('webhooks.rotate.description') }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button
                    data-testid="rotate-secret-submit"
                    :disabled="rotating"
                    @click="handleRotate"
                >
                    {{ $t('webhooks.rotate.submit') }}
                </Button>
                <Button variant="outline" @click="open = false">
                    {{ $t('webhooks.rotate.cancel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
