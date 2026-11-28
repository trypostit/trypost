<script setup lang="ts">
import { computed, ref } from 'vue';

import TimezoneSelect from '@/components/app/TimezoneSelect.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { TimezoneOption } from '@/types/app/posting-schedule';

const props = defineProps<{
    timezone: string;
    timezones: TimezoneOption[];
}>();

const emit = defineEmits<{
    change: [timezone: string];
}>();

const pendingTimezone = ref<string | null>(null);

const selectedTimezone = computed({
    get: () => props.timezone,
    set: (value: string) => {
        if (value !== props.timezone) {
            pendingTimezone.value = value;
        }
    },
});

const confirmOpen = computed({
    get: () => pendingTimezone.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pendingTimezone.value = null;
        }
    },
});

const closeConfirmDialog = (): void => {
    confirmOpen.value = false;
};

const confirmTimezone = (): void => {
    const value = pendingTimezone.value;
    pendingTimezone.value = null;

    if (value) {
        emit('change', value);
    }
};
</script>

<template>
    <section
        class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
    >
        <div class="min-w-0">
            <h2 class="text-base leading-5 font-emphasis text-foreground">
                {{ $t('channels.settings_page.timezone_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ $t('channels.settings_page.timezone_description') }}
            </p>
        </div>
        <div class="min-w-0 shrink-0">
            <TimezoneSelect
                v-model="selectedTimezone"
                :options="timezones"
                testid="channel-timezone"
                compact
            />
        </div>

        <Dialog v-model:open="confirmOpen">
            <DialogContent
                :show-close-button="false"
                data-testid="channel-timezone-confirm-dialog"
            >
                <DialogHeader>
                    <DialogTitle>
                        {{ $t('channels.settings_page.timezone_confirm_title') }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'channels.settings_page.timezone_confirm_description',
                                { timezone: pendingTimezone ?? '' },
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        data-testid="channel-timezone-confirm-cancel"
                        @click="closeConfirmDialog"
                    >
                        {{ $t('channels.settings_page.cancel') }}
                    </Button>
                    <Button
                        type="button"
                        data-testid="channel-timezone-confirm-submit"
                        @click="confirmTimezone"
                    >
                        {{ $t('channels.settings_page.timezone_confirm') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
