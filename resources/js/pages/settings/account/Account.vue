<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

import SettingsField from '@/components/settings/SettingsField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { update as accountUpdate } from '@/routes/app/account';

interface AccountData {
    id: string;
    name: string;
    billing_email: string;
}

defineProps<{
    account: AccountData;
    selfHosted: boolean;
}>();
</script>

<template>
    <Head :title="$t('settings.account.title')" />

    <SettingsLayout
        :title="$t('settings.account.title')"
        :description="$t('settings.account.description')"
    >
        <Form
            v-bind="accountUpdate.form()"
            method="put"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <SettingsField
                :label="$t('settings.account.name')"
                for="account-name"
                :error="errors.name"
            >
                <Input
                    id="account-name"
                    name="name"
                    :default-value="account.name"
                    :placeholder="$t('settings.account.name_placeholder')"
                />
            </SettingsField>

            <SettingsField
                v-if="!selfHosted"
                :label="$t('settings.account.billing_email')"
                for="billing-email"
                :hint="$t('settings.account.billing_email_hint')"
                :error="errors.billing_email"
            >
                <Input
                    id="billing-email"
                    name="billing_email"
                    type="email"
                    :default-value="account.billing_email"
                    :placeholder="
                        $t('settings.account.billing_email_placeholder')
                    "
                />
            </SettingsField>

            <Button type="submit" class="self-start" :disabled="processing">
                {{ $t('settings.account.submit') }}
            </Button>
        </Form>
    </SettingsLayout>
</template>
