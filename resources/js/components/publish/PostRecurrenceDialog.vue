<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { IconInfoCircle } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

import {
    destroy as stopRecurrence,
    update as updateRecurrence,
} from '@/actions/App/Http/Controllers/App/PostRecurrenceController';
import InputError from '@/components/InputError.vue';
import PostRecurrenceSummary from '@/components/publish/PostRecurrenceSummary.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import {
    isRecurring,
    MAX_RECURRENCE_INTERVAL,
    MAX_RECURRENCE_TIMES,
    RECURRENCE_FREQUENCIES,
    recurrenceRuleOf,
} from '@/lib/recurrence';
import type { RecurrenceFrequency, RecurrenceRule } from '@/lib/recurrence';
import type { PostCard } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    testKey: string;
    timezone: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const closeDialog = (): void => {
    open.value = false;
};

const DEFAULT_RULE: RecurrenceRule = { interval: 1, frequency: 'week', times: 1 };

const form = useForm<{
    interval: number;
    frequency: RecurrenceFrequency;
    times: number;
}>({ ...DEFAULT_RULE });

const stopping = ref(false);

const recurring = computed(() => isRecurring(props.post));

watch(open, (value) => {
    if (!value) {
        return;
    }

    const rule = recurrenceRuleOf(props.post) ?? DEFAULT_RULE;

    form.clearErrors();
    form.interval = rule.interval;
    form.frequency = rule.frequency;
    form.times = rule.times;
});

const isWithin = (value: number, max: number): boolean =>
    Number.isInteger(value) && value >= 1 && value <= max;

const previewRule = computed<RecurrenceRule | null>(() =>
    isWithin(form.interval, MAX_RECURRENCE_INTERVAL) &&
    isWithin(form.times, MAX_RECURRENCE_TIMES)
        ? {
              interval: form.interval,
              frequency: form.frequency,
              times: form.times,
          }
        : null,
);

const errorMessage = computed(
    () =>
        form.errors.interval ??
        form.errors.frequency ??
        form.errors.times ??
        (form.errors as Record<string, string | undefined>).post ??
        null,
);

const save = (): void => {
    form.patch(updateRecurrence.url(props.post.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
    });
};

const stop = (): void => {
    stopping.value = true;

    router.delete(stopRecurrence.url(props.post.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
        onFinish: () => {
            stopping.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-lg"
            :data-testid="`post-recurrence-dialog-${testKey}`"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('posts.recurrence.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('posts.recurrence.description') }}
                </DialogDescription>
            </DialogHeader>

            <form
                :id="`post-recurrence-form-${testKey}`"
                class="flex flex-col gap-4"
                @submit.prevent="save"
            >
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <label :for="`post-recurrence-interval-${testKey}`">
                        {{ $t('posts.recurrence.repeat_every') }}
                    </label>
                    <Input
                        :id="`post-recurrence-interval-${testKey}`"
                        v-model.number="form.interval"
                        type="number"
                        inputmode="numeric"
                        class="w-16"
                        :aria-label="$t('posts.recurrence.interval_label')"
                        :aria-invalid="!!form.errors.interval"
                        :data-testid="`post-recurrence-interval-${testKey}`"
                    />
                    <NativeSelect
                        v-model="form.frequency"
                        class="w-32"
                        :aria-label="$t('posts.recurrence.frequency_label')"
                        :data-testid="`post-recurrence-frequency-${testKey}`"
                    >
                        <NativeSelectOption
                            v-for="frequency in RECURRENCE_FREQUENCIES"
                            :key="frequency"
                            :value="frequency"
                        >
                            {{
                                $tChoice(
                                    `posts.recurrence.frequency.${frequency}`,
                                    Number(form.interval) || 1,
                                )
                            }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <span>{{ $t('posts.recurrence.for') }}</span>
                    <Input
                        v-model.number="form.times"
                        type="number"
                        inputmode="numeric"
                        class="w-16"
                        :aria-label="$t('posts.recurrence.times_label')"
                        :aria-invalid="!!form.errors.times"
                        :data-testid="`post-recurrence-times-${testKey}`"
                    />
                    <span>{{
                        $tChoice(
                            'posts.recurrence.times',
                            Number(form.times) || 1,
                        )
                    }}</span>
                </div>

                <InputError :message="errorMessage ?? undefined" />

                <p
                    v-if="previewRule && post.scheduled_at"
                    class="flex items-start gap-2 rounded-lg bg-secondary p-3 text-sm text-foreground"
                >
                    <IconInfoCircle class="mt-0.5 size-4 shrink-0" />
                    <PostRecurrenceSummary
                        :scheduled-at="post.scheduled_at"
                        :timezone="timezone"
                        :rule="previewRule"
                        :data-testid="`post-recurrence-summary-${testKey}`"
                    />
                </p>
            </form>

            <DialogFooter>
                <Button variant="ghost" @click="closeDialog">
                    {{ $t('posts.recurrence.cancel') }}
                </Button>
                <Button
                    v-if="recurring"
                    variant="outline"
                    :disabled="stopping || form.processing"
                    :data-testid="`post-recurrence-stop-${testKey}`"
                    @click="stop"
                >
                    {{ $t('posts.recurrence.stop') }}
                </Button>
                <Button
                    type="submit"
                    :form="`post-recurrence-form-${testKey}`"
                    :disabled="form.processing || stopping"
                    :data-testid="`post-recurrence-save-${testKey}`"
                >
                    {{ $t('posts.recurrence.save') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
