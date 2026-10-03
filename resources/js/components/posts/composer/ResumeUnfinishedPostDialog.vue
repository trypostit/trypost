<script setup lang="ts">
import ComposerAccountStack from '@/components/posts/composer/ComposerAccountStack.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ComposerAccount } from '@/composables/usePostComposition';

defineProps<{
    open: boolean;
    accounts: ComposerAccount[];
    preview: string;
    mediaCount: number;
}>();

const emit = defineEmits<{
    (event: 'update:open', value: boolean): void;
    (event: 'discard'): void;
    (event: 'resume'): void;
}>();
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="sm:max-w-md"
            data-testid="composer-resume-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('posts.composer.resume.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('posts.composer.resume.body') }}
                </DialogDescription>
            </DialogHeader>
            <div
                v-if="accounts.length || preview || mediaCount"
                class="flex min-w-0 items-center gap-3 rounded-lg border p-3"
            >
                <ComposerAccountStack
                    v-if="accounts.length"
                    :accounts="accounts"
                    data-testid="composer-resume-accounts"
                />
                <p
                    v-if="preview"
                    class="min-w-0 truncate text-sm"
                    data-testid="composer-resume-preview"
                >
                    {{ preview }}
                </p>
                <p
                    v-else-if="mediaCount"
                    class="min-w-0 truncate text-sm text-muted-foreground"
                    data-testid="composer-resume-preview"
                >
                    {{
                        $tChoice('posts.composer.resume.media_count', mediaCount, {
                            count: String(mediaCount),
                        })
                    }}
                </p>
            </div>
            <DialogFooter>
                <Button
                    type="button"
                    variant="ghost"
                    data-testid="composer-resume-discard"
                    @click="emit('discard')"
                >
                    {{ $t('posts.composer.resume.discard') }}
                </Button>
                <Button
                    type="button"
                    data-testid="composer-resume-confirm"
                    @click="emit('resume')"
                >
                    {{ $t('posts.composer.resume.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
