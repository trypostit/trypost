<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
import AppLayout from '@/layouts/AppLayout.vue';
import { edit as accountEdit, update as accountUpdate } from '@/routes/app/account';
import { index as billingIndex } from '@/routes/app/billing';

interface AccountData {
    id: string;
    name: string;
    billing_email: string;
}

defineProps<{
    account: AccountData;
    selfHosted: boolean;
}>();

const { canManageBilling } = useWorkspaceRole();

const tabs = computed(() => [
    { name: 'account', label: trans('settings.account.tabs.account'), href: accountEdit().url },
    ...(canManageBilling.value
        ? [{ name: 'billing', label: trans('settings.account.tabs.billing'), href: billingIndex().url }]
        : []),
]);
</script>

<template>
    <Head :title="$t('settings.account.title')" />

    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <PageHeader
                :title="$t('settings.hub.title')"
                :description="$t('settings.hub.description')"
            />

            <SettingsTabsNav :tabs="tabs" active="account" />

            <section class="space-y-12">
                <div class="space-y-6">
                    <HeadingSmall
                        :title="$t('settings.account.title')"
                        :description="$t('settings.account.description')"
                    />

                    <Form
                        v-bind="accountUpdate.form()"
                        method="put"
                        v-slot="{ errors, processing }"
                        class="space-y-6"
                    >
                        <div class="grid gap-2">
                            <Label for="account-name">{{ $t('settings.account.name') }}</Label>
                            <Input
                                id="account-name"
                                name="name"
                                :default-value="account.name"
                                :placeholder="trans('settings.account.name_placeholder')"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div v-if="!selfHosted" class="grid gap-2">
                            <Label for="billing-email">{{ $t('settings.account.billing_email') }}</Label>
                            <Input
                                id="billing-email"
                                name="billing_email"
                                type="email"
                                :default-value="account.billing_email"
                                :placeholder="trans('settings.account.billing_email_placeholder')"
                            />
                            <p class="text-sm text-muted-foreground">
                                {{ $t('settings.account.billing_email_hint') }}
                            </p>
                            <InputError :message="errors.billing_email" />
                        </div>

                        <Button type="submit" :disabled="processing">
                            {{ $t('settings.account.submit') }}
                        </Button>
                    </Form>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
