<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { IconEye, IconEyeOff, IconMail } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

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
import AuthBase from '@/layouts/AuthLayout.vue';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    email?: string | null;
    redirect?: string | null;
    invite?: string | null;
}>();

const showPassword = ref(false);
const showEmailForm = ref(false);

const page = usePage();
const isSelfHosted = computed(() => Boolean(page.props.selfHosted));
const hasSocial = computed(
    () =>
        Boolean(page.props.googleAuthEnabled) ||
        Boolean(page.props.githubAuthEnabled),
);

// With no social providers the email form is the only way to sign up, so it
// stays visible; otherwise it is revealed by the "Sign up with email" toggle.
const emailFormVisible = computed(() => !hasSocial.value || showEmailForm.value);
</script>

<template>
    <AuthBase
        :title="$t('auth.register.title')"
        :description="$t('auth.register.description')"
    >
        <Head :title="$t('auth.register.page_title')" />

        <div class="flex flex-col gap-6">
            <div v-if="hasSocial" class="flex flex-col gap-2">
                <SocialLogin mode="signup" hide-divider />

                <Button
                    v-if="!showEmailForm"
                    type="button"
                    variant="outline"
                    class="w-full"
                    dusk="register-email-toggle"
                    @click="showEmailForm = true"
                >
                    <IconMail class="size-4" />
                    {{ $t('auth.register.signup_with_email') }}
                </Button>
            </div>

            <Form
                v-bind="store.form()"
                :reset-on-success="['password']"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <input v-if="redirect" type="hidden" name="redirect" :value="redirect" />
                <input v-if="invite" type="hidden" name="invite" :value="invite" />

                <div
                    v-if="hasSocial && showEmailForm"
                    class="relative text-center text-sm after:absolute after:inset-0 after:top-1/2 after:z-0 after:flex after:items-center after:border-t after:border-border"
                >
                    <span class="relative z-10 bg-background px-2 text-muted-foreground">{{ $t('auth.or_continue_with_email') }}</span>
                </div>

                <Transition
                    enter-active-class="transition-all duration-300 ease-out"
                    enter-from-class="-translate-y-2 opacity-0"
                    enter-to-class="translate-y-0 opacity-100"
                >
                    <div v-if="emailFormVisible" class="grid gap-6">
                        <div class="grid gap-2">
                            <Label for="name">{{ $t('auth.register.name') }}</Label>
                            <Input
                                id="name"
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
                                type="email"
                                :tabindex="2"
                                autocomplete="email"
                                name="email"
                                placeholder="email@example.com"
                                :default-value="email ?? ''"
                                :readonly="Boolean(invite)"
                                :aria-readonly="Boolean(invite)"
                                :class="{ 'pointer-events-none opacity-60': invite }"
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
                                    :placeholder="$t('auth.register.password')"
                                />
                                <div class="absolute inset-y-0 end-0 flex items-center pe-3">
                                    <TooltipProvider>
                                        <Tooltip>
                                            <TooltipTrigger as-child>
                                                <button
                                                    type="button"
                                                    :tabindex="-1"
                                                    class="cursor-pointer text-muted-foreground hover:text-foreground"
                                                    @click="showPassword = !showPassword"
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
                            <InputError :message="errors.password" />
                        </div>

                        <Button
                            type="submit"
                            class="mt-2 w-full"
                            tabindex="4"
                            :disabled="processing"
                            data-test="register-user-button"
                        >
                            <Spinner v-if="processing" />
                            {{ $t('auth.register.submit') }}
                        </Button>
                    </div>
                </Transition>

                <div class="text-center text-sm text-muted-foreground">
                    {{ $t('auth.register.has_account') }}
                    <TextLink
                        :href="login()"
                        class="underline underline-offset-4"
                        :tabindex="5"
                        >{{ $t('auth.register.log_in') }}</TextLink
                    >
                </div>
            </Form>

            <!-- eslint-disable-next-line vue/no-v-html -->
            <div
                v-if="!isSelfHosted"
                class="text-center text-xs text-muted-foreground [&_a]:underline [&_a]:underline-offset-4 [&_a]:hover:text-primary"
                v-html="$t('auth.legal')"
            />
        </div>
    </AuthBase>
</template>
