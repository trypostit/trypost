<script setup lang="ts">
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';

defineProps<{
    title: string;
    description: string;
    action: string;
    testId: string;
    cancelTestId: string;
    actionTestId: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ confirm: []; cancel: [] }>();

const onOpenChange = (value: boolean): void => {
    open.value = value;
    if (!value) emit('cancel');
};

const confirm = (): void => {
    emit('confirm');
};
</script>

<template>
    <AlertDialog :open="open" @update:open="onOpenChange">
        <AlertDialogContent
            class="gap-0 px-0 pt-6 pb-0 sm:max-w-[512px]"
            :data-testid="testId"
        >
            <AlertDialogHeader class="gap-3 px-6 pb-4 text-left">
                <AlertDialogTitle class="font-sans text-base font-medium">
                    {{ title }}
                </AlertDialogTitle>
                <AlertDialogDescription class="text-sm text-foreground">
                    {{ description }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter class="mx-2 mb-2">
                <AlertDialogCancel :data-testid="cancelTestId">
                    {{ $t('common.cancel') }}
                </AlertDialogCancel>
                <Button
                    variant="destructive"
                    size="lg"
                    :data-testid="actionTestId"
                    @click="confirm"
                >
                    {{ action }}
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
