<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { review as reviewPostAi } from '@/routes/app/posts/ai';

interface Suggestion {
    original: string;
    suggestion: string;
    reason: string;
}

const props = defineProps<{
    postId: string;
    content: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    (e: 'apply', original: string, suggestion: string): void;
}>();

const status = ref<'idle' | 'loading' | 'completed' | 'failed'>('idle');
const suggestions = ref<Suggestion[]>([]);
const appliedSet = ref<Set<number>>(new Set());
const errorMessage = ref<string | null>(null);

const httpReview = useHttp<{ content: string }>({ content: '' });

const startReview = async () => {
    status.value = 'loading';
    suggestions.value = [];
    appliedSet.value = new Set();
    errorMessage.value = null;

    httpReview.content = props.content;
    try {
        const data = (await httpReview.post(reviewPostAi.url(props.postId))) as {
            suggestions?: Suggestion[];
        };
        suggestions.value = data.suggestions ?? [];
        status.value = 'completed';
    } catch {
        status.value = 'failed';
        errorMessage.value = 'Network error';
    }
};

const applySuggestion = (index: number, s: Suggestion) => {
    if (appliedSet.value.has(index)) return;
    emit('apply', s.original, s.suggestion);
    const next = new Set(appliedSet.value);
    next.add(index);
    appliedSet.value = next;
};

const applyAll = () => {
    suggestions.value.forEach((s, idx) => {
        if (! appliedSet.value.has(idx)) {
            emit('apply', s.original, s.suggestion);
        }
    });
    appliedSet.value = new Set(suggestions.value.map((_, idx) => idx));
    open.value = false;
};

const noIssues = computed(() => status.value === 'completed' && suggestions.value.length === 0);
const hasSuggestions = computed(() => status.value === 'completed' && suggestions.value.length > 0);
const allApplied = computed(
    () => suggestions.value.length > 0 && appliedSet.value.size === suggestions.value.length,
);

watch(open, (isOpen) => {
    if (isOpen) {
        startReview();
    } else {
        status.value = 'idle';
        suggestions.value = [];
        appliedSet.value = new Set();
        errorMessage.value = null;
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{ $t('posts.ai.review.title') }}</DialogTitle>
                <DialogDescription>{{ $t('posts.ai.review.description') }}</DialogDescription>
            </DialogHeader>

            <p v-if="status === 'loading'" class="py-8 text-center text-sm font-medium text-foreground/60">
                {{ $t('posts.ai.review.loading') }}
            </p>

            <p v-else-if="status === 'failed'" class="py-4 text-sm font-semibold text-rose-700">{{ errorMessage }}</p>

            <p v-else-if="noIssues" class="py-8 text-center text-sm font-medium text-foreground/60">
                {{ $t('posts.ai.review.no_issues') }}
            </p>

            <div v-else-if="suggestions.length > 0" class="max-h-[400px] space-y-3 overflow-y-auto pr-1">
                <article
                    v-for="(s, idx) in suggestions"
                    :key="idx"
                    class="rounded-xl border-2 border-foreground bg-card px-4 py-3 shadow-2xs transition-opacity"
                    :class="appliedSet.has(idx) ? 'opacity-50' : ''"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm leading-relaxed">
                                <span class="rounded-md border-2 border-foreground bg-rose-100 px-1.5 py-0.5 font-bold text-rose-700 line-through decoration-rose-700/60 shadow-2xs break-words">
                                    {{ s.original }}
                                </span>
                                <span class="font-bold text-foreground/40">→</span>
                                <span class="rounded-md border-2 border-foreground bg-emerald-100 px-1.5 py-0.5 font-bold text-emerald-700 shadow-2xs break-words">
                                    {{ s.suggestion }}
                                </span>
                            </p>
                            <p class="text-xs font-medium text-foreground/60">{{ s.reason }}</p>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="appliedSet.has(idx)"
                            @click="applySuggestion(idx, s)"
                        >
                            {{ appliedSet.has(idx) ? $t('posts.ai.review.applied') : $t('posts.ai.review.apply') }}
                        </Button>
                    </div>
                </article>
            </div>

            <DialogFooter>
                <Button
                    v-if="hasSuggestions"
                    :disabled="allApplied"
                    @click="applyAll"
                >
                    {{ $t('posts.ai.review.apply_all') }}
                </Button>
                <Button variant="outline" @click="open = false">
                    {{ $t('posts.ai.review.cancel') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
