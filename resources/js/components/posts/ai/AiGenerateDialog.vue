<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAiStream } from '@/composables/echo/useAiStream';
import { generate as generatePostAi } from '@/routes/app/posts/ai';

const props = defineProps<{
    postId: string;
    currentContent: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    (e: 'apply', content: string): void;
}>();

const prompt = ref('');
const dispatching = ref(false);
const { text, status, errorMessage, subscribe, unsubscribe, reset } = useAiStream();

const httpGenerate = useHttp<{ prompt: string; current_content: string | null }>({
    prompt: '',
    current_content: null,
});

const startGeneration = async () => {
    if (! prompt.value.trim()) return;
    dispatching.value = true;
    httpGenerate.prompt = prompt.value;
    httpGenerate.current_content = props.currentContent || null;
    try {
        const data = (await httpGenerate.post(generatePostAi.url(props.postId))) as { channel: string };
        subscribe(data.channel);
    } catch {
        status.value = 'failed';
        errorMessage.value = 'Could not start generation';
    } finally {
        dispatching.value = false;
    }
};

// The streamer agent shares the structured-output prompt template with
// PostContentGenerator, so what comes through the stream is a JSON object
// like {"content": "...", "image_title": "...", ...}. We only want the
// content field — and only once the JSON is complete (mid-stream the parse
// fails and we show nothing, avoiding the typewriter-of-JSON effect).
const previewText = computed(() => {
    if (! text.value) return '';
    try {
        const parsed = JSON.parse(text.value);
        if (parsed && typeof parsed.content === 'string') return parsed.content;
    } catch {
        // Mid-stream the JSON is incomplete — leave preview empty.
    }
    return '';
});

const apply = () => {
    emit('apply', previewText.value);
    open.value = false;
};

const retry = () => {
    unsubscribe();
    reset();
    startGeneration();
};

const canApply = computed(() => status.value === 'completed' && previewText.value.trim().length > 0);
const canRetry = computed(() => status.value === 'completed' || status.value === 'failed');

watch(open, () => {
    unsubscribe();
    reset();
    prompt.value = '';
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ $t('posts.ai.generate.title') }}</DialogTitle>
                <DialogDescription>{{ $t('posts.ai.generate.description') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="grid gap-2">
                    <Label for="ai-generate-prompt">{{ $t('posts.ai.generate.prompt_label') }}</Label>
                    <Textarea
                        id="ai-generate-prompt"
                        v-model="prompt"
                        :placeholder="$t('posts.ai.generate.prompt_placeholder')"
                        :disabled="status === 'streaming'"
                        rows="3"
                    />
                </div>

                <div v-if="status !== 'idle'" class="grid gap-2">
                    <Label class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.ai.generate.preview_label') }}</Label>
                    <div class="min-h-[120px] whitespace-pre-wrap break-words rounded-lg border-2 border-foreground bg-card px-3 py-2 text-sm font-medium text-foreground shadow-2xs">{{ previewText || '...' }}</div>
                    <p v-if="status === 'failed'" class="text-xs font-semibold text-rose-700">{{ errorMessage }}</p>
                </div>
            </div>

            <DialogFooter>
                <Button
                    v-if="status === 'idle' || status === 'streaming'"
                    :loading="dispatching || status === 'streaming'"
                    :disabled="! prompt.trim()"
                    @click="startGeneration"
                >
                    {{ $t('posts.ai.generate.start') }}
                </Button>
                <Button v-if="canApply" @click="apply">
                    {{ $t('posts.ai.generate.apply') }}
                </Button>
                <Button v-if="canRetry" variant="outline" @click="retry">
                    {{ $t('posts.ai.generate.retry') }}
                </Button>
                <Button variant="outline" @click="open = false">
                    {{ $t('posts.ai.generate.cancel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
