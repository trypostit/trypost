<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

import ApiKeyController from '@/actions/App/Http/Controllers/App/ApiKeyController';
import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const open = defineModel<boolean>('open', { default: false });

const expiresAt = ref('');

const onSuccess = () => {
    expiresAt.value = '';
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    $t('settings.api_keys.create_dialog.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ $t('settings.api_keys.create_dialog.description') }}
                </DialogDescription>
            </DialogHeader>
            <Form
                v-bind="ApiKeyController.store.form()"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="onSuccess"
            >
                <div class="grid gap-2">
                    <Label for="token-name">{{
                        $t('settings.api_keys.create_dialog.name')
                    }}</Label>
                    <Input
                        id="token-name"
                        name="name"
                        :placeholder="
                            trans(
                                'settings.api_keys.create_dialog.name_placeholder',
                            )
                        "
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label>{{
                        $t('settings.api_keys.create_dialog.expires')
                    }}</Label>
                    <DatePicker
                        name="token-expires"
                        v-model="expiresAt"
                        :show-time="false"
                        :placeholder="
                            trans(
                                'settings.api_keys.create_dialog.expires_placeholder',
                            )
                        "
                    />
                    <input type="hidden" name="expires_at" :value="expiresAt" />
                    <InputError :message="errors.expires_at" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="open = false"
                    >
                        {{ $t('settings.api_keys.create_dialog.cancel') }}
                    </Button>
                    <Button type="submit" :disabled="processing">
                        {{ $t('settings.api_keys.create_dialog.submit') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
