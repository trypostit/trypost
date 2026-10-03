<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import { Button } from '@/components/ui/button';
import { extractErrorMessage } from '@/lib/httpError';
import type { MediaEdit } from '@/lib/mediaEditor';
import { altText as generateAltTextRoute } from '@/routes/app/posts/ai';

const props = defineProps<{
    mediaId: string;
}>();

const edit = defineModel<MediaEdit>('edit', { required: true });

const http = useHttp({ media_id: '' });
const generating = ref(false);
const error = ref<string | null>(null);

const generate = async (): Promise<void> => {
    const target = edit.value;
    generating.value = true;
    error.value = null;
    http.media_id = props.mediaId;
    try {
        const result = (await http.post(generateAltTextRoute.url())) as {
            alt_text: string;
        };
        target.altText = result.alt_text;
    } catch (exception) {
        error.value =
            extractErrorMessage(exception) ??
            trans('posts.composer.media_editor.alt_generate_error');
    } finally {
        generating.value = false;
    }
};
</script>

<template>
    <div class="space-y-3">
        <div class="space-y-1">
            <label
                for="media-editor-alt-text"
                class="block text-sm font-medium"
            >
                {{ $t('posts.composer.media_editor.alt_heading') }}
            </label>
            <p class="text-xs text-muted-foreground">
                {{ $t('posts.composer.media_editor.alt_hint') }}
            </p>
        </div>
        <textarea
            id="media-editor-alt-text"
            v-model="edit.altText"
            data-testid="media-editor-alt-text"
            rows="5"
            class="w-full rounded-lg border border-border bg-background p-3 text-sm"
            :placeholder="$t('posts.composer.media_editor.alt_placeholder')"
        />
        <Button
            type="button"
            variant="outline"
            class="w-full"
            data-testid="media-editor-alt-generate"
            :disabled="generating"
            @click="generate"
        >
            <IconSparkles class="size-4" />
            {{ $t('posts.composer.media_editor.alt_generate') }}
        </Button>
        <p
            v-if="error"
            class="text-sm text-destructive"
            data-testid="media-editor-alt-error"
        >
            {{ error }}
        </p>
    </div>
</template>
