<script setup lang="ts">
import { watch } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAiMediaRegeneration, type RegenerationPayload } from '@/composables/useAiMediaRegeneration';
import type { MediaItem } from '@/types/media';

const props = defineProps<{
    postId: string;
    mediaItem: MediaItem | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    (e: 'regenerated', payload: RegenerationPayload): void;
}>();

const { instruction, errorMessage, instructionError, status, isBusy, isProcessing, canSubmit, submit, resetState, blockDismissWhileBusy } = useAiMediaRegeneration({
    postId: props.postId,
    getMediaItem: () => props.mediaItem,
    onRegenerated: (payload) => emit('regenerated', payload),
    onCompleted: () => {
        open.value = false;
    },
});

watch(open, (isOpen) => {
    if (!isOpen) {
        if (isProcessing.value) {
            open.value = true;
            return;
        }
        resetState();
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-xl"
            :show-close-button="!isBusy"
            @pointer-down-outside="blockDismissWhileBusy"
            @escape-key-down="blockDismissWhileBusy"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('posts.ai.image_regenerate.title') }}</DialogTitle>
                <DialogDescription>{{ $t('posts.ai.image_regenerate.description') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="space-y-2">
                    <Label for="ai-image-instruction">{{ $t('posts.ai.image_regenerate.instruction_label') }}</Label>
                    <Textarea
                        id="ai-image-instruction"
                        v-model="instruction"
                        :disabled="isBusy"
                        :placeholder="$t('posts.ai.image_regenerate.instruction_placeholder')"
                        rows="4"
                    />
                    <InputError :message="instructionError" />
                </div>

                <p v-if="status === 'processing'" class="text-sm text-foreground/70">
                    {{ $t('posts.ai.image_regenerate.processing') }}
                </p>
                <p v-if="errorMessage" class="text-sm font-semibold text-rose-700">{{ errorMessage }}</p>
            </div>

            <DialogFooter>
                <Button
                    :loading="isBusy"
                    :disabled="!canSubmit"
                    @click="submit"
                >
                    {{ $t('posts.ai.image_regenerate.submit') }}
                </Button>
                <Button variant="outline" :disabled="isBusy" @click="open = false">
                    {{ $t('posts.ai.image_regenerate.cancel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
