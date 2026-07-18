<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';

import ImageCropperDialog from '@/components/ImageCropperDialog.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

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
const cropSrc = ref<string | null>(null);
const cropFileName = ref('image.png');
const cropMime = ref('image/png');

watch(cropOpen, (isOpen) => {
    if (!isOpen) {
        cropSrc.value = null;
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

    cropFileName.value = file.name || 'image.png';
    cropMime.value = file.type || 'image/png';

    const reader = new FileReader();
    reader.onload = () => {
        cropSrc.value = reader.result as string;
        cropOpen.value = true;
    };
    reader.readAsDataURL(file);

    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const uploadCropped = (file: File) => {
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

        <ImageCropperDialog
            v-model:open="cropOpen"
            :src="cropSrc"
            :file-name="cropFileName"
            :mime-type="cropMime"
            @cropped="uploadCropped"
        />
    </div>
</template>
