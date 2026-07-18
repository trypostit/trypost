<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import GalleryBrowser from '@/components/assets/GalleryBrowser.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { type MediaType } from '@/lib/mediaType';

interface PickedMedia {
    id: string;
    path: string;
    url: string;
    type: MediaType;
    mime_type: string;
    original_filename?: string;
    size?: number;
    meta?: { width?: number; height?: number; duration?: number };
}

const emit = defineEmits<{
    (e: 'select', media: PickedMedia[]): void;
}>();

const isOpen = ref(false);
const selected = ref<PickedMedia[]>([]);
const selectedCount = computed(() => selected.value.length);

const reset = () => {
    selected.value = [];
};

const open = () => {
    reset();
    isOpen.value = true;
};

const close = () => {
    isOpen.value = false;
};

const confirmSelection = () => {
    if (selected.value.length === 0) return;
    emit('select', selected.value);
    isOpen.value = false;
};

defineExpose({ open, close });
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="flex h-[85vh] max-w-[calc(100%-2rem)] flex-col gap-0 p-0 sm:max-w-5xl">
            <DialogHeader class="border-b px-6 py-4">
                <DialogTitle>{{ trans('posts.edit.media_picker.title') }}</DialogTitle>
            </DialogHeader>

            <div class="flex-1 overflow-y-auto px-6 py-4">
                <GalleryBrowser v-model:selected="selected" mode="picker" />
            </div>

            <DialogFooter class="border-t px-6 py-3">
                <Button type="button" :disabled="selectedCount === 0" @click="confirmSelection">
                    <template v-if="selectedCount > 0">
                        {{ trans('posts.edit.media_picker.add_count', { count: String(selectedCount) }) }}
                    </template>
                    <template v-else>
                        {{ trans('posts.edit.media_picker.add') }}
                    </template>
                </Button>
                <Button type="button" variant="ghost" @click="close">
                    {{ trans('posts.edit.media_picker.cancel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
