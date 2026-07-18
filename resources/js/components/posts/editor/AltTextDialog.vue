<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { MediaItem } from '@/types/media';

const MAX_ALT_TEXT_LENGTH = 2000;

const props = defineProps<{
    mediaItem: MediaItem | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    (e: 'save', value: string): void;
}>();

const value = ref('');

const length = computed(() => [...value.value.trim()].length);
const isOverLimit = computed(() => length.value > MAX_ALT_TEXT_LENGTH);

watch(open, (isOpen) => {
    if (isOpen) {
        value.value = props.mediaItem?.meta?.alt_text ?? '';
    }
});

const save = () => {
    if (isOverLimit.value) {
        return;
    }

    emit('save', value.value);
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl" data-testid="alt-text-dialog">
            <DialogHeader>
                <DialogTitle>{{ $t('posts.edit.alt_text.edit') }}</DialogTitle>
                <DialogDescription>{{ $t('posts.edit.alt_text.hint') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-2">
                <Label for="alt-text-input">{{ $t('posts.edit.alt_text.label') }}</Label>
                <Textarea
                    id="alt-text-input"
                    v-model="value"
                    :placeholder="$t('posts.edit.alt_text.placeholder')"
                    rows="4"
                    data-testid="alt-text-input"
                />
                <p
                    class="text-right text-xs tabular-nums"
                    :class="isOverLimit ? 'text-destructive' : 'text-foreground/60'"
                    data-testid="alt-text-counter"
                >
                    {{ length }} / {{ MAX_ALT_TEXT_LENGTH }}
                </p>
            </div>

            <DialogFooter>
                <Button data-testid="alt-text-save" :disabled="isOverLimit" @click="save">
                    {{ $t('posts.edit.alt_text.save') }}
                </Button>
                <Button variant="outline" @click="open = false">
                    {{ $t('posts.edit.cancel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
