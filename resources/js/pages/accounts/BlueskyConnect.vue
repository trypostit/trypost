<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconInfoCircle } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PopupLayout from '@/layouts/PopupLayout.vue';
import { store as storeBluesky } from '@/routes/app/social/bluesky';

const form = useForm({ identifier: '', password: '' });

const onSubmit = () => form.post(storeBluesky.url());
</script>

<template>
    <PopupLayout :title="$t('accounts.bluesky.title')">
        <div class="max-w-md mx-auto">
            <div class="flex items-center gap-3 mb-6">
                <img src="/images/accounts/bluesky.png" alt="Bluesky" class="h-10 w-10" />
                <div>
                    <h1 class="font-heading text-xl font-medium tracking-tight">{{ $t('accounts.bluesky.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ $t('accounts.bluesky.description') }}</p>
                </div>
            </div>

            <form @submit.prevent="onSubmit" class="space-y-4">
                <div class="space-y-2">
                    <Label for="identifier">{{ $t('accounts.bluesky.email') }}</Label>
                    <Input id="identifier" v-model="form.identifier" type="text" data-testid="bluesky-identifier"
                        :placeholder="trans('accounts.bluesky.email_placeholder')" :class="{ 'border-destructive': form.errors.identifier }"
                    />
                    <p v-if="form.errors.identifier" class="text-sm text-destructive">
                        {{ form.errors.identifier }}
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="password">{{ $t('accounts.bluesky.app_password') }}</Label>
                    <Input id="password" v-model="form.password" type="password" data-testid="bluesky-password"
                        :placeholder="trans('accounts.bluesky.app_password_placeholder')" :class="{ 'border-destructive': form.errors.password }"
                    />
                    <p v-if="form.errors.password" class="text-sm text-destructive">
                        {{ form.errors.password }}
                    </p>
                </div>

                <Alert>
                    <IconInfoCircle class="h-4 w-4" />
                    <AlertDescription class="inline">
                        <span v-html="$t('accounts.bluesky.app_password_hint')" />
                    </AlertDescription>
                </Alert>

                <Button type="submit" :disabled="form.processing" class="w-full" data-testid="bluesky-submit">
                    {{ form.processing ? $t('accounts.bluesky.submitting') : $t('accounts.bluesky.submit') }}
                </Button>
            </form>
        </div>
    </PopupLayout>
</template>
