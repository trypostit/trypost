<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, useTemplateRef } from 'vue';

import ProfileController from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    hasPassword: boolean;
}>();

const page = usePage();
const userEmail = computed(() => page.props.auth.user.email);

const passwordInput = useTemplateRef('passwordInput');
const emailInput = useTemplateRef('emailInput');

const focusFirstInput = () => {
    if (props.hasPassword) {
        passwordInput.value?.$el?.focus();
    } else {
        emailInput.value?.$el?.focus();
    }
};
</script>

<template>
    <div class="space-y-6">
        <HeadingSmall
            :title="$t('settings.delete_account.heading')"
            :description="$t('settings.delete_account.description')"
        />
        <div
            class="space-y-4 rounded-md border border-destructive/30 bg-destructive/5 p-4"
        >
            <div class="space-y-0.5">
                <p class="text-sm font-medium text-destructive">
                    {{ $t('settings.delete_account.warning') }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ $t('settings.delete_account.warning_message') }}
                </p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button
                        variant="destructive"
                        data-test="delete-user-button"
                    >
                        {{ $t('settings.delete_account.button') }}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="ProfileController.destroy.form()"
                        reset-on-success
                        @error="focusFirstInput"
                        :options="{
                            preserveScroll: true,
                        }"
                        class="space-y-6"
                        v-slot="{ errors, processing, reset, clearErrors }"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>{{
                                $t('settings.delete_account.modal_title')
                            }}</DialogTitle>
                            <DialogDescription>
                                {{
                                    hasPassword
                                        ? $t(
                                              'settings.delete_account.modal_description_password',
                                          )
                                        : trans(
                                              'settings.delete_account.modal_description_email',
                                              { email: userEmail },
                                          )
                                }}
                            </DialogDescription>
                        </DialogHeader>

                        <div v-if="hasPassword" class="grid gap-2">
                            <Label for="password" class="sr-only">
                                {{ $t('settings.delete_account.password') }}
                            </Label>
                            <Input
                                id="password"
                                type="password"
                                name="password"
                                ref="passwordInput"
                                :placeholder="
                                    trans(
                                        'settings.delete_account.password_placeholder',
                                    )
                                "
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div v-else class="grid gap-2">
                            <Label for="email_confirmation" class="sr-only"
                                >Email</Label
                            >
                            <Input
                                id="email_confirmation"
                                type="email"
                                name="email_confirmation"
                                ref="emailInput"
                                :placeholder="
                                    trans(
                                        'settings.delete_account.email_placeholder',
                                    )
                                "
                                autocomplete="off"
                            />
                            <InputError :message="errors.email_confirmation" />
                        </div>

                        <DialogFooter>
                            <DialogClose as-child>
                                <Button
                                    variant="outline"
                                    @click="
                                        () => {
                                            clearErrors();
                                            reset();
                                        }
                                    "
                                >
                                    {{ $t('settings.delete_account.cancel') }}
                                </Button>
                            </DialogClose>

                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-delete-user-button"
                            >
                                {{ $t('settings.delete_account.confirm') }}
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
