<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { IconMailForward } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { update as updateEmail } from '@/routes/verification/email';

defineProps<{
    status?: string;
    email?: string;
}>();

const COOLDOWN_SECONDS = 45;

const secondsLeft = ref(COOLDOWN_SECONDS);
let cooldownTimer: ReturnType<typeof setInterval> | null = null;

const startCooldown = () => {
    if (cooldownTimer) clearInterval(cooldownTimer);
    secondsLeft.value = COOLDOWN_SECONDS;
    cooldownTimer = setInterval(() => {
        secondsLeft.value -= 1;
        if (secondsLeft.value <= 0 && cooldownTimer) {
            clearInterval(cooldownTimer);
            cooldownTimer = null;
        }
    }, 1000);
};

const cooldownActive = computed(() => secondsLeft.value > 0);
const cooldownProgress = computed(() => 1 - secondsLeft.value / COOLDOWN_SECONDS);

const editingEmail = ref(false);

onMounted(startCooldown);

onBeforeUnmount(() => {
    if (cooldownTimer) clearInterval(cooldownTimer);
});
</script>

<template>
    <AuthLayout
        :title="$t('auth.verify_email.title')"
        :description="$t('auth.verify_email.description')"
    >
        <Head :title="$t('auth.verify_email.page_title')" />

        <div class="space-y-6 text-center">
            <div
                class="mx-auto flex size-14 items-center justify-center rounded-full bg-primary/10"
            >
                <IconMailForward class="size-7 text-primary" />
            </div>

            <div v-if="email" class="space-y-1">
                <p class="text-sm text-muted-foreground">
                    {{ $t('auth.verify_email.sent_to') }}
                </p>
                <p class="text-sm font-semibold" dusk="verify-email-address">
                    {{ email }}
                </p>
            </div>

            <p class="text-sm text-muted-foreground">
                {{ $t('auth.verify_email.instructions') }}
            </p>

            <div
                v-if="status === 'verification-link-sent'"
                class="text-sm font-medium text-green-600"
                dusk="verify-link-sent"
            >
                {{ $t('auth.verify_email.link_sent') }}
            </div>

            <Form
                v-bind="send.form()"
                class="space-y-6"
                v-slot="{ processing }"
                @success="startCooldown"
            >
                <Button
                    :disabled="processing || cooldownActive"
                    variant="secondary"
                    class="relative overflow-hidden"
                    dusk="verify-resend-button"
                >
                    <span
                        v-if="cooldownActive"
                        class="absolute inset-y-0 left-0 bg-foreground/10 transition-[width] duration-1000 ease-linear"
                        :style="{ width: `${cooldownProgress * 100}%` }"
                    />
                    <Spinner v-if="processing" />
                    <span class="relative tabular-nums">
                        {{ $t('auth.verify_email.resend')
                        }}<template v-if="cooldownActive">
                            ({{ secondsLeft }}s)</template
                        >
                    </span>
                </Button>

                <TextLink
                    :href="logout()"
                    as="button"
                    class="mx-auto block text-sm"
                >
                    {{ $t('auth.verify_email.log_out') }}
                </TextLink>
            </Form>

            <div class="border-t border-border pt-4">
                <button
                    v-if="!editingEmail"
                    type="button"
                    class="mx-auto block cursor-pointer text-sm text-muted-foreground underline-offset-2 hover:underline"
                    dusk="verify-wrong-email"
                    @click="editingEmail = true"
                >
                    {{ $t('auth.verify_email.wrong_email') }}
                </button>

                <Form
                    v-else
                    v-bind="updateEmail.form()"
                    class="space-y-3 text-left"
                    v-slot="{ errors, processing }"
                    @success="editingEmail = false; startCooldown()"
                >
                    <div class="grid gap-1.5">
                        <Label for="new-email">{{ $t('auth.verify_email.new_email_label') }}</Label>
                        <Input
                            id="new-email"
                            type="email"
                            name="email"
                            :placeholder="email"
                            autocomplete="email"
                            dusk="verify-new-email"
                        />
                        <InputError :message="errors.email" />
                    </div>
                    <div class="flex items-center gap-2">
                        <Button :disabled="processing" size="sm" dusk="verify-update-email-submit">
                            <Spinner v-if="processing" />
                            {{ $t('auth.verify_email.update_email') }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="editingEmail = false"
                        >
                            {{ $t('auth.verify_email.cancel') }}
                        </Button>
                    </div>
                </Form>
            </div>
        </div>
    </AuthLayout>
</template>
