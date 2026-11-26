<script setup lang="ts">
import {
    IconAt,
    IconExternalLink,
    IconUserPlus,
    IconX,
} from '@tabler/icons-vue';
import { computed, nextTick, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { instagramProfileUrl } from '@/lib/instagram';
import {
    isValidInstagramUsername,
    normalizeUsername,
    USER_TAGS_MAX,
} from '@/lib/mediaEditor';
import type { MediaEdit } from '@/types/media-editor';

const edit = defineModel<MediaEdit>('edit', { required: true });
const pendingPoint = defineModel<{ x: number; y: number } | null>(
    'pendingPoint',
    { required: true },
);

const clearPendingPoint = (): void => {
    pendingPoint.value = null;
};

const tagging = defineModel<boolean>('tagging', { required: true });
const movingIndex = defineModel<number | null>('movingIndex', {
    required: true,
});

const toggleTagging = (): void => {
    movingIndex.value = null;
    tagging.value = !tagging.value;
};

const toggleMove = (index: number): void => {
    tagging.value = false;
    movingIndex.value = movingIndex.value === index ? null : index;
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
    movingIndex.value = null;
    edit.value.userTags = edit.value.userTags.filter(
        (_, tagIndex) => tagIndex !== index,
    );
};

const movingUsername = computed(() =>
    movingIndex.value === null
        ? null
        : (edit.value.userTags[movingIndex.value]?.username ?? null),
);
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-3">
            <div class="flex items-baseline justify-between gap-2">
                <h3 class="text-sm font-medium">
                    {{ $t('posts.composer.media_editor.tags_heading') }}
                </h3>
                <span
                    class="text-xs text-muted-foreground tabular-nums"
                    data-testid="media-editor-tags-count"
                    >{{ edit.userTags.length }} / {{ USER_TAGS_MAX }}</span
                >
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

            <ol
                v-if="edit.userTags.length"
                class="divide-y divide-border overflow-hidden rounded-lg border border-border"
            >
                <li
                    v-for="(tag, index) in edit.userTags"
                    :key="`${tag.username}-${index}`"
                    class="flex items-center gap-2.5 py-1.5 pr-1.5 pl-3 text-sm transition-colors"
                    :class="
                        movingIndex === index
                            ? 'bg-primary-selected'
                            : 'hover:bg-muted/60'
                    "
                    :data-testid="`media-editor-tag-${index}`"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-medium tabular-nums"
                        :class="
                            movingIndex === index
                                ? 'bg-primary-strong text-primary-strong-foreground'
                                : 'bg-primary-selected text-primary-text'
                        "
                        >{{ index + 1 }}</span
                    >
                    <a
                        :href="instagramProfileUrl(tag.username)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex min-w-0 flex-1 items-center gap-1 font-medium underline-offset-2 hover:underline"
                        :data-testid="`media-editor-tag-link-${index}`"
                    >
                        <span class="truncate">@{{ tag.username }}</span>
                        <IconExternalLink
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </a>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-7 shrink-0 px-2 text-xs"
                        :data-testid="`media-editor-tag-move-${index}`"
                        :aria-pressed="movingIndex === index"
                        @click="toggleMove(index)"
                    >
                        <span data-single-line>{{
                            $t(
                                movingIndex === index
                                    ? 'posts.composer.media_editor.tags_cancel'
                                    : 'posts.composer.media_editor.tags_move',
                            )
                        }}</span>
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0 text-muted-foreground hover:text-foreground"
                        :data-testid="`media-editor-tag-remove-${index}`"
                        :aria-label="
                            $t('posts.composer.media_editor.tags_remove', {
                                username: tag.username,
                            })
                        "
                        @click="removeTag(index)"
                    >
                        <IconX class="size-4" />
                    </Button>
                </li>
            </ol>

            <p
                v-if="movingUsername"
                class="text-xs text-muted-foreground"
                data-testid="media-editor-tag-move-hint"
            >
                {{
                    $t('posts.composer.media_editor.tags_move_hint', {
                        username: movingUsername,
                    })
                }}
            </p>
        </div>


        <div class="space-y-2">
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

        <p class="text-xs text-muted-foreground">
            {{ $t('posts.composer.media_editor.tags_limitations') }}
        </p>
    </div>
</template>
