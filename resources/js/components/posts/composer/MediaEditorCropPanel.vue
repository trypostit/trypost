<script setup lang="ts">
import {
    IconFlipHorizontal,
    IconFlipVertical,
    IconFocusCentered,
    IconMarquee2,
    IconRectangle,
    IconRectangleVertical,
    IconRotate2,
    IconRotateClockwise2,
    IconSquare,
} from '@tabler/icons-vue';

import type { CropPreset, CropPresetValue, MediaEdit } from '@/lib/mediaEditor';

defineProps<{
    presets: CropPreset[];
    centerDisabled: boolean;
    resetDisabled: boolean;
}>();

const edit = defineModel<MediaEdit>('edit', { required: true });

const toggleFlipX = (): void => {
    edit.value.flipX = !edit.value.flipX;
};

const toggleFlipY = (): void => {
    edit.value.flipY = !edit.value.flipY;
};

const emit = defineEmits<{
    (e: 'center'): void;
    (e: 'reset'): void;
}>();

const PRESET_ICONS = {
    freeform: IconMarquee2,
    portrait: IconRectangleVertical,
    square: IconSquare,
    landscape: IconRectangle,
} as const;

const ICON_BUTTON =
    'flex h-8 items-center justify-center rounded-lg border border-border bg-muted transition-colors hover:bg-accent';
const TEXT_BUTTON =
    'flex h-8 items-center justify-center gap-1.5 rounded-lg border border-border bg-muted text-sm font-medium transition-colors enabled:hover:bg-accent disabled:text-subtle-foreground';

const presetTitle = (value: CropPresetValue): string =>
    value === 'freeform' || value === 'original'
        ? `posts.composer.media_editor.preset_${value}`
        : value;

const presetHint = (preset: CropPreset): string =>
    preset.value === 'freeform' || preset.value === 'original'
        ? `posts.composer.media_editor.preset_${preset.value}_hint`
        : `posts.composer.media_editor.preset_${preset.shape}_hint`;

const selectPreset = (value: CropPresetValue): void => {
    edit.value.preset = value;
    edit.value.selection = null;
};

const rotate = (direction: 1 | -1): void => {
    edit.value.quarterTurns = (edit.value.quarterTurns + direction + 4) % 4;
    edit.value.selection = null;
};
</script>

<template>
    <div class="space-y-6">
        <section v-if="presets.length > 0" class="space-y-2">
            <h3 class="text-sm font-medium">
                {{ $t('posts.composer.media_editor.crop_heading') }}
            </h3>
            <button
                v-for="preset in presets"
                :key="preset.value"
                type="button"
                :data-testid="`crop-aspect-${preset.value.replace(/[:.]/g, '-')}`"
                :aria-pressed="edit.preset === preset.value"
                class="flex h-11 w-full items-center gap-3 rounded-lg border py-2 pr-3 pl-2 text-left transition-colors"
                :class="
                    edit.preset === preset.value
                        ? 'border-primary-strong bg-primary-selected text-primary-text'
                        : 'border-border bg-muted'
                "
                @click="selectPreset(preset.value)"
            >
                <component
                    :is="PRESET_ICONS[preset.shape]"
                    class="size-4 shrink-0"
                    :class="
                        edit.preset === preset.value
                            ? 'text-primary-text'
                            : 'text-muted-foreground'
                    "
                />
                <span class="leading-tight">
                    <span class="block text-xs font-medium">{{
                        $t(presetTitle(preset.value))
                    }}</span>
                    <span
                        class="block text-xs"
                        :class="
                            edit.preset === preset.value
                                ? 'text-primary-text'
                                : 'text-muted-foreground'
                        "
                        >{{ $t(presetHint(preset)) }}</span
                    >
                </span>
            </button>
        </section>

        <section class="space-y-3">
            <h3 class="text-sm font-medium">
                {{ $t('posts.composer.media_editor.rotate_heading') }}
            </h3>
            <div class="grid grid-cols-4 gap-2">
                <button
                    type="button"
                    data-testid="media-editor-rotate-left"
                    :class="ICON_BUTTON"
                    :aria-label="$t('posts.composer.media_editor.rotate_left')"
                    @click="rotate(-1)"
                >
                    <IconRotate2 class="size-4" />
                </button>
                <button
                    type="button"
                    data-testid="media-editor-rotate-right"
                    :class="ICON_BUTTON"
                    :aria-label="$t('posts.composer.media_editor.rotate_right')"
                    @click="rotate(1)"
                >
                    <IconRotateClockwise2 class="size-4" />
                </button>
                <button
                    type="button"
                    data-testid="media-editor-flip-horizontal"
                    :class="ICON_BUTTON"
                    :aria-label="
                        $t('posts.composer.media_editor.flip_horizontal')
                    "
                    :aria-pressed="edit.flipX"
                    @click="toggleFlipX"
                >
                    <IconFlipVertical class="size-4" />
                </button>
                <button
                    type="button"
                    data-testid="media-editor-flip-vertical"
                    :class="ICON_BUTTON"
                    :aria-label="$t('posts.composer.media_editor.flip_vertical')"
                    :aria-pressed="edit.flipY"
                    @click="toggleFlipY"
                >
                    <IconFlipHorizontal class="size-4" />
                </button>
            </div>
            <label class="block space-y-1 text-sm">
                <span class="flex justify-between">
                    <span>{{
                        $t('posts.composer.media_editor.straighten')
                    }}</span>
                    <span class="text-muted-foreground"
                        >{{ edit.straighten }}°</span
                    >
                </span>
                <input
                    v-model.number="edit.straighten"
                    data-testid="media-editor-straighten"
                    type="range"
                    min="-45"
                    max="45"
                    step="1"
                    class="editor-range w-full"
                />
            </label>
            <div class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    data-testid="media-editor-center"
                    :class="TEXT_BUTTON"
                    :disabled="centerDisabled"
                    @click="emit('center')"
                >
                    <IconFocusCentered class="size-4" />
                    {{ $t('posts.composer.media_editor.center') }}
                </button>
                <button
                    type="button"
                    data-testid="media-editor-reset"
                    :class="TEXT_BUTTON"
                    :disabled="resetDisabled"
                    @click="emit('reset')"
                >
                    {{ $t('posts.composer.media_editor.reset') }}
                </button>
            </div>
        </section>
    </div>
</template>
