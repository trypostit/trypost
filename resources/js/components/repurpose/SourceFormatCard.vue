<script setup lang="ts">
import { computed } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import SearchableSelect from '@/components/SearchableSelect.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import type { ChannelAccount } from '@/types/channel';
import type { RepurposeSourceFormat, SourceFormatOption } from '@/types/repurpose';

const props = defineProps<{
    accounts: ChannelAccount[];
    formats: SourceFormatOption[];
    error?: string;
}>();

const account = defineModel<string>('account', { required: true });
const format = defineModel<RepurposeSourceFormat>('format', { required: true });

const accountOptions = computed(() =>
    props.accounts.map((item) => ({
        value: item.id,
        label: item.display_name,
        platform: item.platform,
    })),
);
</script>

<template>
    <Card data-testid="repurpose-source-card">
        <CardHeader>
            <CardTitle>{{ $t('repurposes.source.title') }}</CardTitle>
            <CardDescription>{{ $t('repurposes.source.description') }}</CardDescription>
        </CardHeader>

        <CardContent class="space-y-4">
            <div class="space-y-1">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">
                    {{ $t('repurposes.source.account_label') }}
                </p>

                <div data-testid="source-account-select">
                    <SearchableSelect
                        v-model="account"
                        :options="accountOptions"
                        :placeholder="$t('repurposes.create.source_placeholder')"
                        :search-placeholder="$t('repurposes.create.source_search')"
                        :empty-text="$t('repurposes.create.source_empty')"
                        :invalid="Boolean(error)"
                    >
                        <template #option="{ option, compact }">
                            <img
                                v-if="compact"
                                :src="getPlatformLogo(option.platform)"
                                :alt="getPlatformLabel(option.platform)"
                                class="size-4 shrink-0 rounded-sm"
                            />
                            <PlatformLogo
                                v-else
                                :platform="option.platform"
                                size="sm"
                                :data-testid="`source-option-${option.value}`"
                            />

                            <span v-if="compact" class="truncate">{{ option.label }}</span>
                            <span v-else class="min-w-0 text-left">
                                <span class="block truncate text-sm font-bold">{{ option.label }}</span>
                                <span class="block truncate text-xs text-muted-foreground">
                                    {{ getPlatformLabel(option.platform) }}
                                </span>
                            </span>
                        </template>
                    </SearchableSelect>
                </div>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            </div>

            <div class="space-y-1 sm:max-w-xs">
                <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">
                    {{ $t('repurposes.source.watch_label') }}
                </p>

                <Select v-model="format">
                    <SelectTrigger class="w-full" data-testid="source-format-select">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in formats" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </CardContent>
    </Card>
</template>
