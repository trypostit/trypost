<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';

import InputError from '@/components/InputError.vue';
import MemberAccessFields from '@/components/members/MemberAccessFields.vue';
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
import { store as storeInvite } from '@/routes/app/invites';

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const isAdmin = ref(false);
const requiresApproval = ref(false);

const onSuccess = (): void => {
    isAdmin.value = false;
    requiresApproval.value = false;
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="invite-member-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('settings.members.invite.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ $t('settings.members.invite.description') }}
                </DialogDescription>
            </DialogHeader>
            <Form
                v-bind="storeInvite.form()"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="onSuccess"
            >
                <div class="grid gap-2">
                    <Label for="invite-email">{{
                        $t('settings.members.invite.email')
                    }}</Label>
                    <Input
                        id="invite-email"
                        name="email"
                        type="email"
                        data-testid="invite-email"
                        :placeholder="
                            $t('settings.members.invite.email_placeholder')
                        "
                    />
                    <InputError :message="errors.email" />
                </div>

                <MemberAccessFields
                    v-model:is-admin="isAdmin"
                    v-model:requires-approval="requiresApproval"
                />
                <InputError
                    :message="errors.is_admin ?? errors.requires_approval"
                />

                <DialogFooter>
                    <Button
                        variant="ghost"
                        type="button"
                        data-testid="invite-member-cancel"
                        @click="closeDialog"
                    >
                        {{ $t('settings.members.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-testid="invite-member-submit"
                    >
                        {{ $t('settings.members.invite.submit') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
