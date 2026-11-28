<script setup lang="ts">
import { IconBulb } from '@tabler/icons-vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
</script>

<template>
    <div data-testid="create-header">
        <HeaderTitle :title="$t('create.title')" :icon="IconBulb" />
    </div>
</template>
