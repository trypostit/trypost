<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { IconLoader2, IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
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
import { Textarea } from '@/components/ui/textarea';
import { extractErrorMessage } from '@/lib/httpError';
import { generate } from '@/routes/app/create/ideas';
import { UNASSIGNED, type IdeaStage } from '@/types/idea';

const props = defineProps<{
    stages: IdeaStage[];
}>();

const STORAGE_KEY = 'trypost.ideas.generate';
const COUNTS = ['3', '5', '10'];

const readAnswers = (): { business: string; audience: string } => {
    try {
        const stored = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? '{}',
        ) as { business?: unknown; audience?: unknown };

        return {
            business:
                typeof stored.business === 'string' ? stored.business : '',
            audience:
                typeof stored.audience === 'string' ? stored.audience : '',
        };
    } catch {
        return { business: '', audience: '' };
    }
};

const writeAnswers = (business: string, audience: string): void => {
    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ business, audience }),
        );
    } catch {
        return;
    }
};

const open = ref(false);
const error = ref('');
const count = ref('5');
const stageKey = ref(UNASSIGNED);

const http = useHttp({
    count: 5,
    business: '',
    audience: '',
    notes: '',
    idea_stage_id: null as string | null,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    error.value = '';

    if (!http.business && !http.audience) {
        const answers = readAnswers();
        http.business = answers.business;
        http.audience = answers.audience;
    }

    if (
        stageKey.value !== UNASSIGNED &&
        !props.stages.some((stage) => stage.id === stageKey.value)
    ) {
        stageKey.value = UNASSIGNED;
    }
});

onBeforeUnmount(() => {
    http.cancel();
});

const stageName = computed(
    () =>
        props.stages.find((stage) => stage.id === stageKey.value)?.name ??
        null,
);

const canSubmit = computed(
    () =>
        !http.processing &&
        http.business.trim().length > 0 &&
        http.audience.trim().length > 0,
);

const submit = async (): Promise<void> => {
    if (!canSubmit.value) {
        return;
    }

    error.value = '';
    http.count = Number(count.value);
    http.idea_stage_id = stageKey.value === UNASSIGNED ? null : stageKey.value;
    writeAnswers(http.business, http.audience);

    try {
        const result = await http.post(generate.url());

        if (!result) {
            error.value =
                Object.values(http.errors)[0] ??
                trans('create.ideas.errors.generate_failed');

            return;
        }

        http.notes = '';
        open.value = false;
        router.reload({
            only: ['board', 'ideas', 'stages', 'unassigned_count'],
            reset: ['ideas'],
        });
    } catch (exception) {
        error.value =
            extractErrorMessage(exception) ??
            trans('create.ideas.errors.generate_failed');
    }
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                class="bg-primary-subtle text-primary-text hover:bg-primary-subtle/80 max-sm:w-8 max-sm:px-0"
                :aria-label="$t('create.ideas.generate.title')"
                data-testid="ideas-generate"
            >
                <IconSparkles class="size-4" />
                <span class="max-sm:sr-only">{{
                    $t('create.ideas.generate.title')
                }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent
            class="w-[min(24rem,calc(100vw-2rem))] p-4"
            align="end"
            data-testid="ideas-generate-popover"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <p class="text-base font-medium">
                    {{ $t('create.ideas.generate.intro') }}
                </p>

                <div class="space-y-1.5">
                    <label
                        for="ideas-generate-business"
                        class="block text-sm font-medium"
                    >
                        {{ $t('create.ideas.generate.business_label') }}
                    </label>
                    <Textarea
                        id="ideas-generate-business"
                        v-model="http.business"
                        class="min-h-16 bg-background"
                        :placeholder="
                            $t('create.ideas.generate.business_placeholder')
                        "
                        data-testid="ideas-generate-business"
                    />
                </div>

                <div class="space-y-1.5">
                    <label
                        for="ideas-generate-audience"
                        class="block text-sm font-medium"
                    >
                        {{ $t('create.ideas.generate.audience_label') }}
                    </label>
                    <Textarea
                        id="ideas-generate-audience"
                        v-model="http.audience"
                        class="min-h-16 bg-background"
                        :placeholder="
                            $t('create.ideas.generate.audience_placeholder')
                        "
                        data-testid="ideas-generate-audience"
                    />
                </div>

                <div class="space-y-1.5">
                    <label
                        for="ideas-generate-notes"
                        class="block text-sm font-medium"
                    >
                        {{ $t('create.ideas.generate.notes_label') }}
                    </label>
                    <Textarea
                        id="ideas-generate-notes"
                        v-model="http.notes"
                        class="min-h-16 bg-background"
                        :placeholder="
                            $t('create.ideas.generate.notes_placeholder')
                        "
                        data-testid="ideas-generate-notes"
                    />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <span class="block text-sm font-medium">
                            {{ $t('create.ideas.generate.count_label') }}
                        </span>
                        <Select v-model="count">
                            <SelectTrigger
                                class="w-full"
                                :aria-label="
                                    $t('create.ideas.generate.count_label')
                                "
                                data-testid="ideas-generate-count"
                            >
                                <SelectValue>{{ count }}</SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in COUNTS"
                                    :key="option"
                                    :value="option"
                                    :data-testid="`ideas-generate-count-option-${option}`"
                                >
                                    {{ option }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="min-w-0 space-y-1.5">
                        <span class="block text-sm font-medium">
                            {{ $t('create.ideas.generate.stage_label') }}
                        </span>
                        <Select v-model="stageKey">
                            <SelectTrigger
                                class="w-full"
                                :aria-label="
                                    $t('create.ideas.generate.stage_label')
                                "
                                data-testid="ideas-generate-stage"
                            >
                                <SelectValue>
                                    <span class="truncate">{{
                                        stageName ??
                                        $t('create.ideas.unassigned')
                                    }}</span>
                                </SelectValue>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    :value="UNASSIGNED"
                                    :data-testid="`ideas-generate-stage-option-${UNASSIGNED}`"
                                >
                                    {{ $t('create.ideas.unassigned') }}
                                </SelectItem>
                                <SelectItem
                                    v-for="stage in stages"
                                    :key="stage.id"
                                    :value="stage.id"
                                    :data-testid="`ideas-generate-stage-option-${stage.id}`"
                                >
                                    {{ stage.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <p
                    v-if="error"
                    role="alert"
                    class="text-sm text-destructive"
                    data-testid="ideas-generate-error"
                >
                    {{ error }}
                </p>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="!canSubmit"
                    data-testid="ideas-generate-submit"
                >
                    <IconLoader2
                        v-if="http.processing"
                        class="size-4 animate-spin"
                    />
                    <IconSparkles v-else class="size-4" />
                    {{ $t('create.ideas.generate.submit') }}
                </Button>

                <p class="text-xs text-muted-foreground">
                    {{ $t('posts.composer.assistant_disclaimer') }}
                </p>
            </form>
        </PopoverContent>
    </Popover>
</template>
