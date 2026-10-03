<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';

import MediaEditorDialog, {
    type MediaEditChange,
} from '@/components/posts/composer/MediaEditorDialog.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { MediaItem } from '@/types/media';

type Props = {
    photoUrl: string | null;
    hasPhoto: boolean;
    name: string;
    uploadUrl: string;
    deleteUrl?: string;
    size?: 'sm' | 'md' | 'lg';
    rounded?: 'full' | 'lg';
    canUpload?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    size: 'md',
    rounded: 'lg',
    deleteUrl: undefined,
    canUpload: true,
});

const fileInput = ref<HTMLInputElement | null>(null);
const uploading = ref(false);

const cropOpen = ref(false);
const cropItems = ref<MediaItem[]>([]);

watch(cropOpen, (isOpen) => {
    if (!isOpen) {
        cropItems.value = [];
    }
});

const sizeClasses = {
    sm: 'size-16',
    md: 'size-20',
    lg: 'size-24',
};

const textSizeClasses = {
    sm: 'text-lg',
    md: 'text-xl',
    lg: 'text-2xl',
};

const roundedClasses = {
    full: 'rounded-full',
    lg: 'rounded-lg',
};

const triggerFileInput = () => {
    fileInput.value?.click();
};

const handleFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    if (!file) {
        return;
    }

    const reader = new FileReader();
    reader.onload = () => {
        cropItems.value = [
            {
                id: 'photo',
                url: reader.result as string,
                type: 'image',
                mime_type: file.type || 'image/png',
                original_filename: file.name || 'image.png',
            },
        ];
        cropOpen.value = true;
    };
    reader.readAsDataURL(file);

    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const uploadCropped = (changes: MediaEditChange[]) => {
    const file = changes[0]?.file;

    if (!file) {
        return;
    }

    uploading.value = true;

    router.post(
        props.uploadUrl,
        { photo: file },
        {
            forceFormData: true,
            onFinish: () => {
                uploading.value = false;
            },
        },
    );
};

const handleDelete = () => {
    if (!props.deleteUrl) {
        return;
    }

    router.delete(props.deleteUrl);
};
</script>

<template>
    <div class="flex items-center gap-4">
        <Avatar
            :src="photoUrl"
            :name="name"
            :class="[sizeClasses[size], roundedClasses[rounded]]"
            :fallback-class="[
                'bg-sidebar-accent text-sidebar-accent-foreground',
                textSizeClasses[size],
            ]"
        />

        <div v-if="canUpload" class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/*"
                    class="hidden"
                    @change="handleFileChange"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="uploading"
                    @click="triggerFileInput"
                >
                    {{ uploading ? trans('common.photo_upload.uploading') : trans('common.photo_upload.upload') }}
                </Button>
                <TooltipProvider v-if="hasPhoto && deleteUrl">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="size-8"
                                @click="handleDelete"
                            >
                                <IconTrash class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>{{ $t('common.photo_upload.remove') }}</TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
            <p class="text-xs text-muted-foreground">
                {{ $t('common.photo_upload.hint') }}
            </p>
        </div>

        <MediaEditorDialog
            v-model:open="cropOpen"
            :items="cropItems"
            :content-types="[]"
            mode="photo"
            @apply="uploadCropped"
        />
    </div>
</template>
