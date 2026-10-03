<script setup lang="ts">
import { IconAt, IconUserPlus, IconX } from '@tabler/icons-vue';
import { computed, nextTick, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import {
    isValidInstagramUsername,
    type MediaEdit,
    normalizeUsername,
    USER_TAGS_MAX,
} from '@/lib/mediaEditor';

const edit = defineModel<MediaEdit>('edit', { required: true });
const pendingPoint = defineModel<{ x: number; y: number } | null>(
    'pendingPoint',
    { required: true },
);

const clearPendingPoint = (): void => {
    pendingPoint.value = null;
};

const tagging = defineModel<boolean>('tagging', { required: true });

const toggleTagging = (): void => {
    tagging.value = !tagging.value;
};

const username = ref('');
const invalid = ref(false);

const clearInvalid = (): void => {
    invalid.value = false;
};

const input = ref<HTMLInputElement | null>(null);

const atLimit = computed(() => edit.value.userTags.length >= USER_TAGS_MAX);

watch(pendingPoint, async (point) => {
    username.value = '';
    invalid.value = false;
    if (point) {
        await nextTick();
        input.value?.focus();
    }
});

const saveTag = (): void => {
    if (!pendingPoint.value || atLimit.value) return;
    if (!isValidInstagramUsername(username.value)) {
        invalid.value = true;
        return;
    }

    edit.value.userTags = [
        ...edit.value.userTags,
        {
            username: normalizeUsername(username.value),
            x: pendingPoint.value.x,
            y: pendingPoint.value.y,
        },
    ];
    pendingPoint.value = null;
};

const removeTag = (index: number): void => {
    edit.value.userTags = edit.value.userTags.filter(
        (_, tagIndex) => tagIndex !== index,
    );
};
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-2">
            <h3 class="text-sm font-medium">
                {{ $t('posts.composer.media_editor.tags_heading') }}
            </h3>
            <Button
                type="button"
                variant="outline"
                class="w-full"
                :data-testid="
                    tagging ? 'media-editor-tags-finish' : 'media-editor-tags-start'
                "
                :disabled="!tagging && atLimit"
                @click="toggleTagging"
            >
                <IconUserPlus class="size-4" />
                {{
                    $t(
                        tagging
                            ? 'posts.composer.media_editor.tags_finish'
                            : 'posts.composer.media_editor.tags_start',
                    )
                }}
            </Button>
            <p
                v-if="atLimit || tagging"
                class="text-xs text-muted-foreground"
            >
                {{
                    atLimit
                        ? $t('posts.composer.media_editor.tags_limit', {
                              max: String(USER_TAGS_MAX),
                          })
                        : $t('posts.composer.media_editor.tags_hint')
                }}
            </p>
        </div>

        <form
            v-if="pendingPoint"
            class="space-y-2"
            data-testid="media-editor-tag-form"
            @submit.prevent="saveTag"
        >
            <div
                class="flex items-center gap-2 rounded-lg border border-border bg-background px-3"
                :class="invalid ? 'border-destructive' : ''"
            >
                <IconAt class="size-4 shrink-0 text-muted-foreground" />
                <input
                    ref="input"
                    v-model="username"
                    data-testid="media-editor-tag-username"
                    class="h-9 w-full bg-transparent text-sm outline-none"
                    autocomplete="off"
                    :placeholder="
                        $t('posts.composer.media_editor.tags_placeholder')
                    "
                    :aria-invalid="invalid"
                    @input="clearInvalid"
                />
            </div>
            <p v-if="invalid" class="text-xs text-destructive">
                {{ $t('posts.composer.media_editor.tags_invalid') }}
            </p>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="clearPendingPoint"
                >
                    {{ $t('posts.composer.media_editor.tags_cancel') }}
                </Button>
                <Button
                    type="submit"
                    size="sm"
                    data-testid="media-editor-tag-save"
                >
                    {{ $t('posts.composer.media_editor.tags_save') }}
                </Button>
            </div>
        </form>

        <ol v-if="edit.userTags.length" class="space-y-2">
            <li
                v-for="(tag, index) in edit.userTags"
                :key="`${tag.username}-${index}`"
                class="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm"
                :data-testid="`media-editor-tag-${index}`"
            >
                <span
                    class="flex size-5 shrink-0 items-center justify-center rounded-full bg-foreground text-[11px] font-medium text-background"
                    >{{ index + 1 }}</span
                >
                <span class="min-w-0 flex-1 truncate">@{{ tag.username }}</span>
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground"
                    :aria-label="
                        $t('posts.composer.media_editor.tags_remove', {
                            username: tag.username,
                        })
                    "
                    @click="removeTag(index)"
                >
                    <IconX class="size-4" />
                </button>
            </li>
        </ol>

        <p class="text-xs text-muted-foreground">
            {{ $t('posts.composer.media_editor.tags_limitations') }}
        </p>
    </div>
</template>
