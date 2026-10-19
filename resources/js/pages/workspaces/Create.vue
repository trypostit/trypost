<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import WorkspaceUpgradeDialog from '@/components/workspaces/WorkspaceUpgradeDialog.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { store as storeWorkspace } from '@/routes/app/workspaces';

const props = defineProps<{
    upgradeRequired: boolean;
}>();

const upgradeDialogOpen = ref(false);

const form = useForm({
    name: '',
});

const openUpgradeDialog = (): void => {
    upgradeDialogOpen.value = true;
};

const openUpgradeDialogWhenRequired = (): void => {
    if (props.upgradeRequired) {
        openUpgradeDialog();
    }
};

const submit = (): void => {
    if (props.upgradeRequired) {
        openUpgradeDialog();

        return;
    }

    form.post(storeWorkspace.url());
};

onMounted(openUpgradeDialogWhenRequired);
</script>

<template>
    <Head :title="$t('workspaces.create.page_title')" />

    <AuthLayout
        :title="$t('workspaces.create.title')"
        :description="$t('workspaces.create.description')"
    >
        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">{{ $t('workspaces.create.name') }}</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    type="text"
                    name="name"
                    autofocus
                    data-testid="workspaces-create-name"
                    :placeholder="$t('workspaces.create.name_placeholder')"
                />
                <InputError :message="form.errors.name" />
            </div>

            <Button
                type="submit"
                class="w-full"
                data-testid="workspaces-create-submit"
                :disabled="form.processing"
            >
                <Spinner v-if="form.processing" />
                {{ $t('workspaces.create.submit') }}
            </Button>
        </form>

        <WorkspaceUpgradeDialog v-model:open="upgradeDialogOpen" />
    </AuthLayout>
</template>
