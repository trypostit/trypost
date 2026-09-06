<script setup lang="ts">
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import type { ChannelAccount } from '@/types/channel';
import type { RepurposeSourceFormat, SourceFormatOption } from '@/types/repurpose';

defineProps<{
    account: ChannelAccount | null | undefined;
    formats: SourceFormatOption[];
}>();

const format = defineModel<RepurposeSourceFormat>({ required: true });
</script>

<template>
    <Card data-testid="repurpose-source-card">
        <CardHeader>
            <CardTitle>{{ $t('repurposes.source.title') }}</CardTitle>
            <CardDescription>{{ $t('repurposes.source.description') }}</CardDescription>
        </CardHeader>

        <CardContent class="space-y-4">
            <div class="group flex items-center gap-3">
                <PlatformLogo :platform="account?.platform ?? ''" />

                <span class="min-w-0">
                    <span class="block truncate text-sm font-bold">{{ account?.display_name }}</span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{ getPlatformLabel(account?.platform ?? '') }}
                    </span>
                </span>
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
