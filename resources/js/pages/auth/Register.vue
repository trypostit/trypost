<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { IconEye, IconEyeOff } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import LegalLinks from '@/components/auth/LegalLinks.vue';
import PasswordRequirements from '@/components/auth/PasswordRequirements.vue';
import type { PasswordRequirement } from '@/components/auth/PasswordRequirements.vue';
import SocialLogin from '@/components/auth/SocialLogin.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useGuestLocale } from '@/composables/useGuestLocale';
import AuthBase from '@/layouts/AuthLayout.vue';
import { detectPreferences } from '@/lib/detectPreferences';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    email?: string | null;
    invite?: string | null;
    passwordRequirements: PasswordRequirement[];
}>();

const { signupLocale } = useGuestLocale();
const detected = detectPreferences();

const showPassword = ref(false);
const password = ref('');

const togglePasswordVisibility = (): void => {
    showPassword.value = !showPassword.value;
};

const page = usePage();
const hasSocial = computed(
    () =>
        Boolean(page.props.googleAuthEnabled) ||
        Boolean(page.props.githubAuthEnabled),
);
</script>

<template>
    <AuthBase
        :title="$t('auth.register.title')"
        panel
    >
        <Head :title="$t('auth.register.page_title')" />

        <div class="flex flex-col gap-6">
            <div v-if="hasSocial" class="flex flex-col gap-2">
                <SocialLogin mode="signup" hide-divider :invite="invite" />
            </div>

            <Form
                v-bind="store.form()"
                :reset-on-success="['password']"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <input v-if="invite" type="hidden" name="invite" :value="invite" />
                <input type="hidden" name="locale" :value="signupLocale" data-testid="register-locale" />
                <input type="hidden" name="timezone" :value="detected.timezone" data-testid="register-timezone" />
                <input type="hidden" name="week_starts_on" :value="detected.week_starts_on" data-testid="register-week-start" />
                <input type="hidden" name="time_format" :value="detected.time_format ?? ''" data-testid="register-time-format" />

                <div
                    v-if="hasSocial"
                    class="flex items-center gap-3 text-sm text-muted-foreground"
                >
                    <span class="h-px flex-1 bg-border-strong" />
                    {{ $t('auth.or_continue_with_email') }}
                    <span class="h-px flex-1 bg-border-strong" />
                </div>

                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <Label for="name">{{ $t('auth.register.name') }}</Label>
                            <Input
                                id="name"
                                data-testid="register-name"
                                type="text"
                                autofocus
                                :tabindex="1"
                                autocomplete="name"
                                name="name"
                                :placeholder="$t('auth.register.name_placeholder')"
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">{{ $t('auth.register.email') }}</Label>
                            <Input
                                id="email"
                                data-testid="register-email"
                                type="email"
                                :tabindex="2"
                                autocomplete="email"
                                name="email"
                                placeholder="email@example.com"
                                :default-value="email ?? ''"
                                :readonly="Boolean(invite)"
                                :aria-readonly="Boolean(invite)"
                                :class="{ 'pointer-events-none bg-muted text-muted-foreground': invite }"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="password">{{ $t('auth.register.password') }}</Label>
                            <div class="relative">
                                <Input
                                    id="password"
                                    :type="showPassword ? 'text' : 'password'"
                                    :tabindex="3"
                                    autocomplete="new-password"
                                    name="password"
                                    v-model="password"
                                    data-testid="register-password"
                                    :placeholder="$t('auth.register.password')"
                                    class="pe-8"
                                />
                                <div class="absolute inset-y-0 end-0 flex items-center pe-2">
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <button
                                                    type="button"
                                                    :tabindex="-1"
                                                    class="cursor-pointer text-muted-foreground hover:text-foreground"
                                                    @click="togglePasswordVisibility"
                                                >
                                                    <IconEyeOff v-if="showPassword" class="size-4" />
                                                    <IconEye v-else class="size-4" />
                                                </button>
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                <p>{{ showPassword ? $t('auth.register.hide_password') : $t('auth.register.show_password') }}</p>
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                </div>
                            </div>
                            <PasswordRequirements
                                :password="password"
                                :requirements="passwordRequirements"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <Button
                            type="submit"
                            class="w-full"
                            :tabindex="4"
                            :disabled="processing"
                            data-testid="register-user-button"
                        >
                            <Spinner v-if="processing" />
                            {{ $t('auth.register.submit') }}
                        </Button>
                    </div>
            </Form>

            <p class="text-center text-sm text-muted-foreground">
                {{ $t('auth.register.has_account') }}
                <TextLink
                    :href="login()"
                    :tabindex="5"
                    data-testid="register-log-in-link"
                    >{{ $t('auth.register.log_in') }}</TextLink
                >
            </p>
        </div>

        <template #footer>
            <LegalLinks />
        </template>
    </AuthBase>
</template>
