<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconLoader2,
    IconRefresh,
    IconSparkles,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { extractErrorMessage } from '@/lib/httpError';
import {
    countPromptWords,
    PROMPT_MAX_LENGTH,
    PROMPT_MIN_WORDS,
} from '@/lib/promptWords';
import { formatNumber } from '@/lib/utils';
import { assist } from '@/routes/app/posts/ai';

export type AssistantChannel = {
    accountId: string;
    platform: string;
    label: string;
    limit: number | null;
};

type RewriteMode =
    | 'rephrase'
    | 'shorten'
    | 'expand'
    | 'more_casual'
    | 'more_formal';

const REWRITE_MODES: RewriteMode[] = [
    'rephrase',
    'shorten',
    'expand',
    'more_casual',
    'more_formal',
];

const props = defineProps<{
    content: string;
    channel: AssistantChannel | null;
}>();

const emit = defineEmits<{
    insert: [text: string];
    replace: [text: string];
}>();

const screen = ref<'actions' | 'prompt' | 'suggestion'>('actions');
const prompt = ref('');
const lastPrompt = ref('');
const suggestion = ref('');
const suggestionAction = ref<'insert' | 'replace'>('insert');
const busy = ref(false);
const error = ref('');
const pendingMode = ref('generate');

const http = useHttp({
    mode: '',
    prompt: '',
    current_content: '',
    previous_content: '',
    platform: null as string | null,
    social_account_id: null as string | null,
});

onBeforeUnmount(() => {
    http.cancel();
});

const spinnerLabel = computed(() =>
    REWRITE_MODES.includes(pendingMode.value as RewriteMode)
        ? trans(`posts.composer.assistant_modes.${pendingMode.value}`)
        : trans('posts.composer.assistant_generate'),
);

const hasContent = computed(() => props.content.trim().length > 0);
const promptWords = computed(() => countPromptWords(prompt.value));
const promptTooShort = computed(() => promptWords.value < PROMPT_MIN_WORDS);
const promptTooLong = computed(() => prompt.value.length > PROMPT_MAX_LENGTH);
const canGenerate = computed(
    () => !busy.value && !promptTooShort.value && !promptTooLong.value,
);

const request = async (
    payload: {
        mode: string;
        prompt?: string;
        current_content?: string;
        previous_content?: string;
    },
    action: 'insert' | 'replace',
): Promise<void> => {
    busy.value = true;
    error.value = '';
    pendingMode.value = payload.mode;
    http.mode = payload.mode;
    http.prompt = payload.prompt ?? '';
    http.current_content = payload.current_content ?? '';
    http.previous_content = payload.previous_content ?? '';
    http.platform = props.channel?.platform ?? null;
    http.social_account_id = props.channel?.accountId ?? null;
    try {
        const result = (await http.post(assist.url())) as
            | { content: string }
            | undefined;
        if (!result) {
            error.value =
                Object.values(http.errors)[0] ??
                trans('posts.composer.assistant_error');
            return;
        }
        suggestion.value = result.content;
        suggestionAction.value = action;
        screen.value = 'suggestion';
    } catch (exception) {
        error.value =
            extractErrorMessage(exception) ??
            trans('posts.composer.assistant_error');
    } finally {
        busy.value = false;
    }
};

const openPrompt = (): void => {
    error.value = '';
    screen.value = 'prompt';
};

const generate = (): void => {
    if (!canGenerate.value) return;
    lastPrompt.value = prompt.value.trim();
    void request({ mode: 'generate', prompt: lastPrompt.value }, 'insert');
};

const regenerate = (): void => {
    void request(
        {
            mode: 'regenerate',
            prompt: lastPrompt.value,
            previous_content: suggestion.value,
        },
        'insert',
    );
};

const rewrite = (mode: RewriteMode): void => {
    void request({ mode, current_content: props.content }, 'replace');
};

const back = (): void => {
    error.value = '';
    screen.value = 'actions';
};

const accept = (): void => {
    if (suggestionAction.value === 'insert') {
        emit('insert', suggestion.value);
    } else {
        emit('replace', suggestion.value);
    }
    suggestion.value = '';
    screen.value = 'actions';
};
</script>

<template>
    <div
        class="flex min-h-full flex-col gap-4"
        data-testid="writing-assistant-panel"
    >
        <template v-if="screen === 'actions'">
            <p class="text-sm">
                {{ $t('posts.composer.assistant_question') }}
            </p>
            <div class="space-y-2">
                <Button
                    type="button"
                    variant="outline"
                    class="w-full justify-start"
                    data-testid="writing-assistant-mode-generate"
                    :disabled="busy"
                    @click="openPrompt"
                >
                    <IconSparkles class="size-4" />
                    {{ $t('posts.composer.assistant_modes.generate') }}
                </Button>
                <Button
                    v-for="mode in REWRITE_MODES"
                    :key="mode"
                    type="button"
                    variant="outline"
                    class="w-full justify-start"
                    :data-testid="`writing-assistant-mode-${mode}`"
                    :disabled="busy || !hasContent"
                    @click="rewrite(mode)"
                >
                    {{ $t(`posts.composer.assistant_modes.${mode}`) }}
                </Button>
            </div>
            <IconLoader2
                v-if="busy"
                class="size-5 animate-spin"
                role="status"
                :aria-label="spinnerLabel"
            />
        </template>

        <template v-else-if="screen === 'prompt'">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                data-testid="writing-assistant-back"
                :aria-label="$t('posts.composer.assistant_back')"
                :disabled="busy"
                @click="back"
            >
                <IconArrowLeft class="size-4" />
            </Button>
            <div class="space-y-2">
                <label
                    for="writing-assistant-prompt"
                    class="block text-sm font-medium"
                >
                    {{ $t('posts.composer.assistant_prompt_question') }}
                </label>
                <Textarea
                    id="writing-assistant-prompt"
                    v-model="prompt"
                    data-testid="writing-assistant-prompt"
                    class="min-h-40 bg-background p-3"
                    :placeholder="
                        $t('posts.composer.assistant_prompt_placeholder')
                    "
                />
                <div
                    class="flex items-start justify-between gap-3 text-xs text-muted-foreground"
                >
                    <p>{{ $t('posts.composer.assistant_tip') }}</p>
                    <span
                        class="shrink-0 tabular-nums"
                        :class="{ 'text-destructive': promptTooLong }"
                        data-testid="writing-assistant-prompt-count"
                    >
                        {{
                            $t('posts.composer.assistant_prompt_count', {
                                count: formatNumber(prompt.length),
                                max: formatNumber(PROMPT_MAX_LENGTH),
                            })
                        }}
                    </span>
                </div>
                <p
                    v-if="promptTooShort"
                    class="text-xs text-muted-foreground"
                    data-testid="writing-assistant-prompt-hint"
                >
                    {{
                        $t('posts.composer.assistant_prompt_min_words', {
                            count: String(PROMPT_MIN_WORDS),
                        })
                    }}
                </p>
            </div>
            <Button
                type="button"
                class="ml-auto"
                data-testid="writing-assistant-generate"
                :disabled="!canGenerate"
                @click="generate"
            >
                <IconLoader2 v-if="busy" class="size-4 animate-spin" />
                <IconSparkles v-else class="size-4" />
                {{ $t('posts.composer.assistant_generate') }}
            </Button>
        </template>

        <template v-else>
            <div class="space-y-3">
                <h4 class="text-sm font-medium">
                    {{ $t('posts.composer.assistant_suggestion') }}
                </h4>
                <p
                    class="rounded-md border bg-background p-3 text-sm whitespace-pre-wrap"
                    data-testid="writing-assistant-suggestion"
                >
                    {{ suggestion }}
                </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    class="mr-auto"
                    data-testid="writing-assistant-back"
                    :disabled="busy"
                    @click="back"
                >
                    <IconArrowLeft class="size-4" />
                    {{ $t('posts.composer.assistant_back') }}
                </Button>
                <Button
                    v-if="suggestionAction === 'insert'"
                    type="button"
                    variant="outline"
                    data-testid="writing-assistant-regenerate"
                    :disabled="busy"
                    @click="regenerate"
                >
                    <IconLoader2 v-if="busy" class="size-4 animate-spin" />
                    <IconRefresh v-else class="size-4" />
                    {{ $t('posts.composer.assistant_regenerate') }}
                </Button>
                <Button
                    v-if="suggestionAction === 'insert'"
                    type="button"
                    data-testid="writing-assistant-insert"
                    :disabled="busy"
                    @click="accept"
                >
                    {{ $t('posts.composer.assistant_insert') }}
                </Button>
                <Button
                    v-else
                    type="button"
                    data-testid="writing-assistant-replace"
                    :disabled="busy"
                    @click="accept"
                >
                    {{ $t('posts.composer.assistant_replace') }}
                </Button>
            </div>
        </template>

        <p
            v-if="error"
            role="alert"
            class="text-sm text-destructive"
            data-testid="writing-assistant-error"
        >
            {{ error }}
        </p>

        <div class="mt-auto space-y-2 border-t pt-4 text-xs text-muted-foreground">
            <p
                v-if="channel && channel.limit !== null"
                data-testid="writing-assistant-channel"
            >
                {{
                    $t('posts.composer.assistant_channel', {
                        platform: channel.label,
                        limit: formatNumber(channel.limit),
                    })
                }}
            </p>
            <p data-testid="writing-assistant-disclaimer">
                {{ $t('posts.composer.assistant_disclaimer') }}
            </p>
        </div>
    </div>
</template>
