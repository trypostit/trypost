<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { AlertDialogCancel } from 'reka-ui';
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

const page = usePage();

const storageKey = (): string =>
    `calendar.skipCustomTimeConfirm.${page.props.auth.user.id}`;

const isOpen = ref(false);
const skipNextTime = ref(false);
const fromTime = ref('');
const toTime = ref('');
const zone = ref('');

let resolveChoice: ((confirmed: boolean) => void) | null = null;

const escapeHtml = (value: string): string =>
    value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');

const readSkip = (): boolean => {
    try {
        return window.localStorage.getItem(storageKey()) === 'true';
    } catch {
        return false;
    }
};

const storeSkip = (): void => {
    try {
        window.localStorage.setItem(storageKey(), 'true');
    } catch {
        return;
    }
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
    fromTime.value = escapeHtml(
        date.formatMonthDayTimeInTimezone(from, timezone),
    );
    toTime.value = escapeHtml(date.formatMonthDayTimeInTimezone(to, timezone));
    zone.value = escapeHtml(timezone);
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
                    class="[&_strong]:font-[650]"
                    data-testid="calendar-custom-time-description"
                    v-html="
                        $t('calendar.reschedule.custom_time.description', {
                            from: fromTime,
                            to: toTime,
                            zone,
                        })
                    "
                />
                <p>{{ $t('calendar.reschedule.custom_time.unqueued') }}</p>
            </AlertDialogDescription>
            <Separator class="max-sm:mt-auto" />
            <div
                class="flex items-center justify-end gap-2 px-6 max-sm:flex-wrap"
                data-testid="calendar-custom-time-footer"
            >
                <label
                    class="me-auto flex shrink-0 items-center gap-2 text-sm leading-[21px] whitespace-nowrap text-foreground max-sm:me-0 max-sm:w-full"
                >
                    <Checkbox
                        :model-value="skipNextTime"
                        data-testid="calendar-custom-time-skip"
                        @update:model-value="setSkipNextTime"
                    />
                    {{ $t('calendar.reschedule.custom_time.dont_show_again') }}
                </label>
                <AlertDialogCancel as-child>
                    <Button
                        variant="ghost"
                        data-testid="calendar-custom-time-cancel"
                    >
                        {{ $t('calendar.reschedule.custom_time.cancel') }}
                    </Button>
                </AlertDialogCancel>
                <Button data-testid="calendar-custom-time-confirm" @click="accept">
                    {{ $t('calendar.reschedule.custom_time.confirm') }}
                </Button>
            </div>
        </AlertDialogContent>
    </AlertDialog>
</template>
