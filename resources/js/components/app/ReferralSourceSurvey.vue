<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import BottomSheet from '@/components/app/BottomSheet.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useBelowBreakpoint } from '@/composables/app/useBreakpoint';
import { store } from '@/routes/app/referral-source';
import type { SharedData } from '@/types';

const page = usePage<SharedData>();

const sources = computed(() => page.props.referralSources ?? []);
const isPhone = useBelowBreakpoint('sm');
const sheetDismissed = ref(false);

const sheetOpen = computed({
    get: () => !sheetDismissed.value,
    set: (open: boolean) => {
        sheetDismissed.value = !open;
    },
});

const container = computed(() =>
    isPhone.value
        ? {
              is: BottomSheet,
              attrs: {
                  open: sheetOpen.value,
                  'onUpdate:open': (open: boolean) => {
                      sheetOpen.value = open;
                  },
                  title: trans('referral_source.title'),
                  testId: 'referral-source-survey',
              },
          }
        : {
              is: 'section',
              attrs: {
                  class: 'fixed end-4 bottom-4 z-40 flex max-h-[calc(100svh-2rem)] w-[calc(100vw-2rem)] max-w-sm flex-col rounded-xl bg-popover p-5 text-popover-foreground shadow-md dark:border dark:border-border',
                  role: 'dialog',
                  'aria-labelledby': 'referral-source-title',
                  'data-testid': 'referral-source-survey',
              },
          },
);

const form = useForm<{ referral_source: string }>({ referral_source: '' });

const choose = (value: unknown): void => {
    form.referral_source = String(value);
};

const submit = (): void => {
    if (form.referral_source === '' || form.processing) {
        return;
    }

    form.submit(store(), { preserveScroll: true, preserveState: true });
};
</script>

<template>
    <component
        :is="container.is"
        v-if="sources.length > 0"
        v-bind="container.attrs"
    >
        <h2
            v-if="!isPhone"
            id="referral-source-title"
            class="text-base font-medium text-foreground"
        >
            {{ $t('referral_source.title') }}
        </h2>

        <div :class="isPhone ? 'flex min-h-0 flex-col px-4 pb-4' : 'contents'">
            <RadioGroup
                :model-value="form.referral_source"
                :aria-labelledby="isPhone ? undefined : 'referral-source-title'"
                :aria-label="isPhone ? $t('referral_source.title') : undefined"
                class="-mx-1 mt-4 flex min-h-0 flex-col gap-0 overflow-y-auto px-1"
                @update:model-value="choose"
            >
                <label
                    v-for="source in sources"
                    :key="source"
                    class="flex cursor-pointer items-center gap-3 py-1.5 text-sm text-foreground"
                >
                    <RadioGroupItem
                        :value="source"
                        :data-testid="`referral-source-option-${source}`"
                    />
                    {{ $t(`referral_source.options.${source}`) }}
                </label>
            </RadioGroup>

            <InputError class="mt-2" :message="form.errors.referral_source" />

            <Button
                type="button"
                class="mt-4"
                :class="isPhone ? 'w-full' : 'self-start'"
                :disabled="form.referral_source === '' || form.processing"
                data-testid="referral-source-submit"
                @click="submit"
            >
                {{ $t('referral_source.submit') }}
            </Button>
        </div>
    </component>
</template>
