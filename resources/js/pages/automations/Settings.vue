<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { IconAlertCircle, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import AutomationDetailLayout from '@/components/automations/AutomationDetailLayout.vue';
import { firstConfigIssue } from '@/components/automations/config-validation';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import date from '@/date';
import {
    activate as activateAutomation,
    destroy as destroyAutomation,
    pause as pauseAutomation,
    update as updateAutomation,
} from '@/routes/app/automations';
import type { Automation } from '@/types/automation/automation';

const props = defineProps<{ automation: Automation }>();

const statusLabel = computed(() =>
    trans(`automations.status.${props.automation.status}`),
);

const statusDot = computed(
    () =>
        ({
            draft: 'bg-foreground/30',
            active: 'bg-emerald-500',
            paused: 'bg-amber-500',
        })[props.automation.status] ?? 'bg-foreground/30',
);

const isActive = computed(() => props.automation.status === 'active');
const isToggling = ref(false);

// A misconfigured node can't be activated (the backend rejects it too); surface
// it up front and block the toggle. Pausing an active automation stays allowed.
const configIssue = computed(() => firstConfigIssue(props.automation.nodes ?? []));
const activationBlocked = computed(() => !isActive.value && configIssue.value !== null);

const statusDetail = computed(() => {
    const automation = props.automation;

    if (automation.status === 'active' && automation.activated_at) {
        return trans('automations.settings.activated_at', { date: date.formatDate(automation.activated_at) });
    }

    if (automation.status === 'paused' && automation.paused_at) {
        return trans('automations.settings.paused_at', { date: date.formatDate(automation.paused_at) });
    }

    return trans('automations.settings.created_at', { date: date.formatDate(automation.created_at) });
});

const toggleActive = () => {
    if (isToggling.value || activationBlocked.value) return;
    isToggling.value = true;
    const url = isActive.value
        ? pauseAutomation.url(props.automation.id)
        : activateAutomation.url(props.automation.id);
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                isToggling.value = false;
            },
            onError: (errors: Record<string, string>) => {
                const fallback = isActive.value
                    ? trans('automations.form.pause_error_fallback')
                    : trans('automations.form.activate_error_fallback');
                toast.error(errors.message ?? fallback);
            },
        },
    );
};

const onNameSaved = () =>
    toast.success(trans('automations.settings.name_saved'));

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const openDeleteModal = () => {
    deleteModal.value?.open({
        url: destroyAutomation.url(props.automation.id),
        confirmText: props.automation.name,
    });
};
</script>

<template>
    <AutomationDetailLayout :automation="automation" current="settings">
        <div class="mx-auto max-w-2xl space-y-8 p-6">
            <section class="space-y-4">
                <HeadingSmall
                    :title="$t('automations.settings.general')"
                    :description="
                        $t('automations.settings.general_description')
                    "
                />

                <Form
                    v-bind="updateAutomation.form(automation.id)"
                    class="grid gap-2"
                    :options="{ preserveScroll: true }"
                    @success="onNameSaved"
                    v-slot="{ errors, processing }"
                >
                    <Label for="name">{{
                        $t('automations.settings.name_label')
                    }}</Label>
                    <div class="flex items-start gap-2">
                        <div class="flex-1">
                            <Input
                                id="name"
                                name="name"
                                :default-value="automation.name"
                            />
                            <InputError :message="errors.name" class="mt-1" />
                        </div>
                        <Button
                            :disabled="processing"
                            >{{ $t('automations.actions.save') }}</Button
                        >
                    </div>
                </Form>
            </section>

            <Separator />

            <section class="space-y-4">
                <HeadingSmall
                    :title="$t('automations.settings.status_title')"
                    :description="$t('automations.settings.status_description')"
                />

                <div
                    class="flex items-center justify-between gap-4 rounded-xl border-2 border-foreground/10 bg-card px-4 py-3.5"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="mt-1.5 size-2.5 shrink-0 rounded-full"
                            :class="statusDot"
                        />
                        <div class="space-y-0.5">
                            <p class="text-sm font-semibold">
                                {{ statusLabel }}
                            </p>
                            <p class="text-xs text-foreground/50">
                                {{ statusDetail }}
                            </p>
                        </div>
                    </div>
                    <Switch
                        :model-value="isActive"
                        :disabled="isToggling || activationBlocked"
                        :aria-label="
                            isActive
                                ? $t('automations.actions.pause')
                                : $t('automations.actions.activate')
                        "
                        @update:model-value="toggleActive"
                    />
                </div>

                <p
                    v-if="activationBlocked"
                    class="flex items-center gap-1.5 text-xs font-medium text-amber-600 dark:text-amber-500"
                >
                    <IconAlertCircle class="size-4 flex-shrink-0" />
                    {{ configIssue }}
                </p>
            </section>

            <Separator />

            <section class="space-y-4">
                <HeadingSmall
                    :title="$t('automations.settings.danger_title')"
                    :description="$t('automations.settings.danger_description')"
                />

                <div
                    class="flex items-center justify-between gap-4 rounded-xl border-2 border-destructive/30 bg-destructive/5 p-4"
                >
                    <div class="text-sm">
                        <p class="font-medium">
                            {{ $t('automations.settings.delete_title') }}
                        </p>
                        <p class="text-foreground/60">
                            {{ $t('automations.settings.delete_description') }}
                        </p>
                    </div>
                    <Button
                        variant="destructive"
                        @click="openDeleteModal"
                    >
                        <IconTrash class="size-4" />
                        {{ $t('automations.actions.delete') }}
                    </Button>
                </div>
            </section>
        </div>

        <ConfirmDeleteModal
            ref="deleteModal"
            :title="$t('automations.delete.title')"
            :description="$t('automations.delete.description')"
            :action="$t('automations.delete.confirm')"
            :cancel="$t('automations.delete.cancel')"
        />
    </AutomationDetailLayout>
</template>
