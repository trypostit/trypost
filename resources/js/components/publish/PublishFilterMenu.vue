<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCheck, IconDotsVertical, IconFilter2 } from '@tabler/icons-vue';
import { computed } from 'vue';

import CalendarStatusFilter from '@/components/publish/CalendarStatusFilter.vue';
import ResponsivePopover from '@/components/ResponsivePopover.vue';
import TimezoneSelect from '@/components/TimezoneSelect.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import type { TimezoneOption } from '@/types/posting-schedule';
import { CALENDAR_STATUSES, type CalendarStatus } from '@/types/publish';

const props = withDefaults(
    defineProps<{
        testId: string;
        timezones: TimezoneOption[];
        showSlots?: boolean | null;
        manageSlotsHref?: string | null;
        compact?: boolean | null;
    }>(),
    { showSlots: null, manageSlotsHref: null, compact: null },
);

const timezone = defineModel<string>('timezone', { required: true });
const status = defineModel<CalendarStatus | null>('status', { default: null });

const emit = defineEmits<{
    'update:showSlots': [value: boolean];
}>();

const belowMd = useBelowBreakpoint('md');
const isCompact = computed(() => props.compact ?? belowMd.value);

const hasSlotOptions = (): boolean =>
    props.showSlots !== null || props.manageSlotsHref !== null;

const setShowSlots = (value: boolean): void => {
    emit('update:showSlots', value);
};

const selectStatus = (value: CalendarStatus): void => {
    status.value = value;
};

const rowClass =
    'flex h-11 w-full items-center gap-2 rounded-md px-2 text-start text-base text-foreground transition-control hover:bg-accent focus-visible:bg-accent focus-visible:outline-none sm:h-9 sm:text-sm';
</script>

<template>
    <ResponsivePopover
        v-if="isCompact"
        :title="$t('posts.table.actions')"
        :test-id="`${testId}-menu-content`"
        content-class="w-72 p-2"
        sheet-class="px-4 pb-6"
    >
        <template #trigger>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 data-[state=open]:bg-accent"
                :aria-label="$t('posts.table.actions')"
                :data-testid="`${testId}-menu`"
            >
                <IconFilter2 class="size-4" />
            </Button>
        </template>

        <template v-if="status !== null">
            <div
                role="group"
                :aria-label="$t('calendar.status.label')"
                data-testid="calendar-status-filter"
            >
                <p class="px-2 pt-1 pb-1.5 text-xs text-muted-foreground">
                    {{ $t('calendar.status.label') }}
                </p>
                <button
                    v-for="option in CALENDAR_STATUSES"
                    :key="option"
                    type="button"
                    :aria-pressed="status === option"
                    :class="rowClass"
                    :data-testid="`calendar-status-${option}`"
                    @click="selectStatus(option)"
                >
                    {{ $t(`calendar.status.${option}`) }}
                    <IconCheck v-if="status === option" class="ms-auto size-4" />
                </button>
            </div>
            <div class="my-2 h-px bg-border" />
        </template>

        <div
            role="group"
            :aria-label="$t('posts.publish.timezone.label')"
            data-testid="publish-timezone-select"
        >
            <TimezoneSelect
                v-model="timezone"
                :options="timezones"
                testid="publish-timezone"
            />
        </div>

        <template v-if="hasSlotOptions()">
            <div class="my-2 h-px bg-border" />
            <button
                v-if="showSlots !== null"
                type="button"
                :aria-pressed="showSlots"
                :class="rowClass"
                :data-testid="`${testId}-toggle-slots`"
                @click="setShowSlots(!showSlots)"
            >
                {{ $t('posts.publish.menu.show_posting_times') }}
                <IconCheck v-if="showSlots" class="ms-auto size-4" />
            </button>
            <Link
                v-if="manageSlotsHref"
                :href="manageSlotsHref"
                :class="rowClass"
                :data-testid="`${testId}-manage-slots`"
            >
                {{ $t('posts.publish.menu.manage_posting_times') }}
            </Link>
        </template>
    </ResponsivePopover>

    <template v-else>
        <CalendarStatusFilter
            v-if="status !== null"
            :model-value="status"
            @update:model-value="selectStatus"
        />
        <slot name="desktop-filters" />
        <div
            class="shrink-0"
            role="group"
            :aria-label="$t('posts.publish.timezone.label')"
            data-testid="publish-timezone-select"
        >
            <TimezoneSelect
                v-model="timezone"
                :options="timezones"
                testid="publish-timezone"
                variant="ghost"
                compact
            />
        </div>
        <DropdownMenu v-if="hasSlotOptions()">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="order-last shrink-0 data-[state=open]:bg-accent"
                    :aria-label="$t('posts.table.actions')"
                    :data-testid="`${testId}-menu`"
                >
                    <IconDotsVertical class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuCheckboxItem
                    v-if="showSlots !== null"
                    :model-value="showSlots"
                    :data-testid="`${testId}-toggle-slots`"
                    @update:model-value="setShowSlots"
                >
                    {{ $t('posts.publish.menu.show_posting_times') }}
                </DropdownMenuCheckboxItem>
                <DropdownMenuItem v-if="manageSlotsHref" as-child>
                    <Link
                        :href="manageSlotsHref"
                        :data-testid="`${testId}-manage-slots`"
                    >
                        {{ $t('posts.publish.menu.manage_posting_times') }}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </template>
</template>
