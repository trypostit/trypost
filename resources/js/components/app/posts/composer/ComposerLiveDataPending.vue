<script setup lang="ts">
import SettingsSection from '@/components/app/posts/editor/SettingsSection.vue';
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <SettingsSection>
        <div class="space-y-2" data-testid="composer-live-data-pending">
            <Skeleton class="h-8 w-full" />
            <Skeleton class="h-8 w-2/3" />
        </div>
    </SettingsSection>
</template>
