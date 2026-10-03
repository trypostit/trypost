<script setup lang="ts">
import { IconInfoCircle } from '@tabler/icons-vue';

import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const isAdmin = defineModel<boolean>('isAdmin', { required: true });
const requiresApproval = defineModel<boolean>('requiresApproval', {
    required: true,
});

const publishingOptions = [
    { value: 'direct', labelKey: 'settings.members.access.publishes_directly' },
    { value: 'approval', labelKey: 'settings.members.access.needs_approval' },
] as const;

const selectPublishing = (value: unknown): void => {
    requiresApproval.value = value === 'approval';
};
</script>

<template>
    <div class="grid gap-4">
        <div
            class="flex items-center justify-between gap-4"
            data-testid="member-access-admin-row"
        >
            <div class="flex min-w-0 flex-col gap-1">
                <Label
                    for="member-access-admin"
                    data-testid="member-access-admin-label"
                    >{{ $t('settings.members.roles.admin') }}</Label
                >
                <p
                    class="text-sm text-muted-foreground"
                    data-testid="member-access-admin-description"
                >
                    {{ $t('settings.members.access.admin_description') }}
                </p>
            </div>
            <Switch
                id="member-access-admin"
                v-model="isAdmin"
                class="shrink-0"
                data-testid="member-access-admin"
            />
        </div>

        <div
            v-if="!isAdmin"
            class="grid gap-2"
            data-testid="member-access-publishing"
        >
            <div class="flex items-center gap-1.5">
                <Label
                    for="member-access-publishing"
                    data-testid="member-access-publishing-label"
                    >{{ $t('settings.members.access.publishing') }}</Label
                >
                <TooltipProvider :delay-duration="150">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                class="text-muted-foreground"
                                :aria-label="
                                    $t('settings.members.access.publishing_help')
                                "
                            >
                                <IconInfoCircle class="size-4" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-xs">
                            {{ $t('settings.members.access.publishing_help') }}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </div>
            <Select
                :model-value="requiresApproval ? 'approval' : 'direct'"
                @update:model-value="selectPublishing"
            >
                <SelectTrigger
                    id="member-access-publishing"
                    class="w-full"
                    data-testid="member-access-publishing-trigger"
                >
                    <SelectValue>{{
                        $t(
                            requiresApproval
                                ? 'settings.members.access.needs_approval'
                                : 'settings.members.access.publishes_directly',
                        )
                    }}</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in publishingOptions"
                        :key="option.value"
                        :value="option.value"
                        :data-testid="`member-access-publishing-${option.value}`"
                        >{{ $t(option.labelKey) }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </div>

        <input type="hidden" name="is_admin" :value="isAdmin ? '1' : '0'" />
        <input
            type="hidden"
            name="requires_approval"
            :value="!isAdmin && requiresApproval ? '1' : '0'"
        />
    </div>
</template>
