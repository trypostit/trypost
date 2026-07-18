<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { index as automationsIndex } from '@/routes/app/automations';
</script>

<template>
    <header
        data-testid="automation-mobile-back"
        class="flex items-center border-b-2 border-foreground/10 bg-card py-3 pl-16 pr-4 md:pl-4 lg:hidden"
    >
        <Link :href="automationsIndex.url()" class="block w-full">
            <Button variant="outline" class="w-full justify-start gap-2">
                <IconArrowLeft class="size-4" />
                {{ $t('common.back') }}
            </Button>
        </Link>
    </header>
</template>
