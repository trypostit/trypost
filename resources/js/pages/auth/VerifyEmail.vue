<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';

import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineProps<{
    status?: string;
}>();
</script>

<template>
    <AuthLayout
        :title="$t('auth.verify_email.title')"
        :description="$t('auth.verify_email.description')"
        :status="
            status === 'verification-link-sent'
                ? $t('auth.verify_email.link_sent')
                : undefined
        "
    >
        <Head :title="$t('auth.verify_email.page_title')" />

        <div class="flex flex-col gap-6">
            <Form v-bind="send.form()" v-slot="{ processing }">
                <Button type="submit" :disabled="processing" class="w-full">
                    <Spinner v-if="processing" />
                    {{ $t('auth.verify_email.resend') }}
                </Button>
            </Form>

            <p class="text-center text-sm text-muted-foreground">
                <TextLink :href="logout()" as="button">
                    {{ $t('auth.verify_email.log_out') }}
                </TextLink>
            </p>
        </div>
    </AuthLayout>
</template>
