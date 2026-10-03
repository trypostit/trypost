<script setup lang="ts">
import { computed, ref } from 'vue';

import EmojiPicker from '@/components/posts/EmojiPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    useTemplateDialogForm,
    type TemplateDialogMode,
} from '@/composables/useTemplateDialogForm';
import { templateVisibilityIcons } from '@/lib/templateVisibility';
import { store, update } from '@/routes/app/create/templates';
import type { PostTemplate, TemplateVisibility } from '@/types/template';

const DEFAULT_EMOJI = '📝';

const props = withDefaults(
    defineProps<{
        mode: TemplateDialogMode;
        template?: PostTemplate | null;
        visibility?: TemplateVisibility;
        canChangeVisibility?: boolean;
    }>(),
    {
        template: null,
        visibility: 'personal',
        canChangeVisibility: true,
    },
);

const emit = defineEmits<{
    saved: [template: PostTemplate];
    close: [];
}>();

const { form, open, processing, errors, close, onOpenChange, sendLocal } =
    useTemplateDialogForm({
        mode: props.mode,
        initial: {
            emoji: props.template?.emoji ?? DEFAULT_EMOJI,
            title: props.template?.title ?? '',
            description: props.template?.description ?? '',
            body: props.template?.body ?? '',
            visibility: props.template?.visibility ?? props.visibility,
        },
        onClose: () => emit('close'),
    });

const emojiOpen = ref(false);

const visibilities: TemplateVisibility[] = ['personal', 'team'];

const isBlank = computed(() => !form.title.trim() || !form.body.trim());

const selectEmoji = (emoji: string): void => {
    form.emoji = emoji;
    emojiOpen.value = false;
};

const save = async (): Promise<void> => {
    if (isBlank.value || processing.value) {
        return;
    }

    if (props.mode === 'page') {
        const options = {
            preserveScroll: true,
            preserveState: 'errors' as const,
            onSuccess: () => close(),
        };

        if (props.template) {
            form.put(update.url(props.template.id), options);
        } else {
            form.post(store.url(), options);
        }

        return;
    }

    const saved = props.template
        ? await sendLocal('put', update.url(props.template.id))
        : await sendLocal('post', store.url());

    if (saved) {
        emit('saved', saved);
        close();
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="flex max-h-[calc(100dvh-2rem)] flex-col gap-4 overflow-y-auto sm:max-w-[420px]"
            :aria-describedby="undefined"
            data-testid="template-editor"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        template
                            ? $t('create.templates.editor.edit_title')
                            : $t('create.templates.editor.create_title')
                    }}
                </DialogTitle>
            </DialogHeader>

            <div class="space-y-1.5">
                <label for="template-editor-title" class="text-sm font-medium">{{
                    $t('create.templates.editor.title_label')
                }}</label>
                <div class="flex items-center gap-2">
                    <Popover v-model:open="emojiOpen">
                        <PopoverTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="shrink-0 text-lg"
                                :aria-label="$t('create.templates.editor.emoji')"
                                data-testid="template-editor-emoji"
                            >
                                {{ form.emoji || DEFAULT_EMOJI }}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent class="w-auto p-0" align="start">
                            <EmojiPicker @select="selectEmoji" />
                        </PopoverContent>
                    </Popover>
                    <Input
                        id="template-editor-title"
                        v-model="form.title"
                        type="text"
                        :placeholder="
                            $t('create.templates.editor.title_placeholder')
                        "
                        data-testid="template-editor-title"
                    />
                </div>
            </div>

            <div class="space-y-1.5">
                <label
                    for="template-editor-description"
                    class="text-sm font-medium"
                    >{{ $t('create.templates.editor.description_label') }}</label
                >
                <Input
                    id="template-editor-description"
                    v-model="form.description"
                    type="text"
                    :placeholder="
                        $t('create.templates.editor.description_placeholder')
                    "
                    data-testid="template-editor-description"
                />
            </div>

            <div class="space-y-1.5">
                <label for="template-editor-body" class="text-sm font-medium">{{
                    $t('create.templates.editor.body_label')
                }}</label>
                <textarea
                    id="template-editor-body"
                    v-model="form.body"
                    rows="6"
                    :placeholder="$t('create.templates.editor.body_placeholder')"
                    class="min-h-32 w-full resize-y rounded-lg border border-input bg-background p-3 text-sm transition-control outline-none placeholder:text-subtle-foreground focus-visible:border-primary-text"
                    data-testid="template-editor-body"
                />
            </div>

            <div class="space-y-1.5">
                <label
                    for="template-editor-visibility"
                    class="text-sm font-medium"
                    >{{ $t('create.templates.editor.visibility') }}</label
                >
                <Select v-model="form.visibility" :disabled="!canChangeVisibility">
                    <SelectTrigger
                        id="template-editor-visibility"
                        class="w-full"
                        :aria-label="$t('create.templates.editor.visibility')"
                        data-testid="template-editor-visibility"
                    >
                        <SelectValue class="flex items-center gap-2">
                            <component
                                :is="templateVisibilityIcons[form.visibility]"
                                class="size-4 shrink-0 text-muted-foreground"
                                :data-testid="`template-editor-visibility-icon-${form.visibility}`"
                            />
                            {{
                                $t(
                                    `create.templates.visibility.${form.visibility}`,
                                )
                            }}
                        </SelectValue>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in visibilities"
                            :key="option"
                            :value="option"
                            :data-testid="`template-editor-visibility-${option}`"
                        >
                            <component
                                :is="templateVisibilityIcons[option]"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            {{ $t(`create.templates.visibility.${option}`) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p
                    v-if="!canChangeVisibility"
                    class="text-xs text-muted-foreground"
                    data-testid="template-editor-visibility-hint"
                >
                    {{ $t('create.templates.editor.visibility_owner_only_hint') }}
                </p>
            </div>

            <div
                v-if="errors.length"
                role="alert"
                class="space-y-1 text-sm text-destructive"
                data-testid="template-editor-error"
            >
                <p v-for="message in errors" :key="message">{{ message }}</p>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    data-testid="template-editor-cancel"
                    @click="close()"
                >
                    {{ $t('create.templates.cancel') }}
                </Button>
                <Button
                    type="button"
                    data-testid="template-editor-save"
                    :disabled="isBlank || processing"
                    @click="save"
                >
                    {{
                        template
                            ? $t('create.templates.editor.save')
                            : $t('create.templates.editor.create')
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
