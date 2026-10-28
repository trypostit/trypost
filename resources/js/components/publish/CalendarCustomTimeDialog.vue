<script setup lang="ts">
import { ref } from 'vue';

import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Separator } from '@/components/ui/separator';
import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

const STORAGE_KEY = 'calendar.skipCustomTimeConfirm';

const isOpen = ref(false);
const skipNextTime = ref(false);
const fromLabel = ref('');
const toLabel = ref('');

let resolveChoice: ((confirmed: boolean) => void) | null = null;

const readSkip = (): boolean => {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'true';
    } catch {
        return false;
    }
};

const storeSkip = (): void => {
    try {
        window.localStorage.setItem(STORAGE_KEY, 'true');
    } catch {
        return;
    }
};

const escapeHtml = (value: string): string =>
    value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');

const strong = (at: string, timezone: string): string => {
    const time = dayjs
        .utc(at)
        .tz(timezone)
        .locale(activeLocale.value.toLowerCase())
        .format(`MMM D ${date.timeToken()}`);

    return `<strong class="font-[650]">${escapeHtml(time)} <i>(${escapeHtml(timezone)})</i></strong>`;
};

const settle = (confirmed: boolean): void => {
    isOpen.value = false;
    resolveChoice?.(confirmed);
    resolveChoice = null;
};

const confirm = (
    from: string,
    to: string,
    timezone: string,
): Promise<boolean> => {
    if (readSkip()) {
        return Promise.resolve(true);
    }

    resolveChoice?.(false);
    fromLabel.value = strong(from, timezone);
    toLabel.value = strong(to, timezone);
    skipNextTime.value = false;
    isOpen.value = true;

    return new Promise((resolve) => {
        resolveChoice = resolve;
    });
};

const onOpenChange = (value: boolean): void => {
    if (!value) {
        settle(false);
    }
};

const cancel = (): void => settle(false);

const accept = (): void => {
    if (skipNextTime.value) {
        storeSkip();
    }

    settle(true);
};

const setSkipNextTime = (value: boolean | 'indeterminate'): void => {
    skipNextTime.value = value === true;
};

defineExpose({ confirm });
</script>

<template>
    <AlertDialog :open="isOpen" @update:open="onOpenChange">
        <AlertDialogContent
            disable-outside-pointer-events
            class="gap-4 px-0 pt-6 pb-4 sm:max-w-[480px]"
            data-testid="calendar-custom-time-dialog"
        >
            <AlertDialogTitle class="px-6 text-base leading-5 font-[550]">
                {{ $t('calendar.reschedule.custom_time.title') }}
            </AlertDialogTitle>
            <AlertDialogDescription
                as="div"
                class="flex flex-col gap-4 px-6 text-sm leading-[21px] text-foreground"
            >
                <p
                    data-testid="calendar-custom-time-description"
                    v-html="
                        $t('calendar.reschedule.custom_time.description', {
                            from: fromLabel,
                            to: toLabel,
                        })
                    "
                />
                <p>{{ $t('calendar.reschedule.custom_time.unqueued') }}</p>
            </AlertDialogDescription>
            <Separator />
            <div class="flex items-center justify-end gap-2 px-6">
                <label
                    class="me-2.5 flex items-center gap-2 text-sm leading-[21px] text-foreground"
                >
                    <Checkbox
                        :model-value="skipNextTime"
                        data-testid="calendar-custom-time-skip"
                        @update:model-value="setSkipNextTime"
                    />
                    {{ $t('calendar.reschedule.custom_time.dont_show_again') }}
                </label>
                <Button
                    variant="ghost"
                    data-testid="calendar-custom-time-cancel"
                    @click="cancel"
                >
                    {{ $t('calendar.reschedule.custom_time.cancel') }}
                </Button>
                <Button data-testid="calendar-custom-time-confirm" @click="accept">
                    {{ $t('calendar.reschedule.custom_time.confirm') }}
                </Button>
            </div>
        </AlertDialogContent>
    </AlertDialog>
</template>
