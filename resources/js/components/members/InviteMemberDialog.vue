<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store as storeInvite } from '@/routes/app/invites';
import { WorkspaceRole } from '@/types/workspace-role';

const open = defineModel<boolean>('open', { default: false });

const inviteRole = ref(WorkspaceRole.Member);

const onSuccess = () => {
    inviteRole.value = WorkspaceRole.Member;
    open.value = false;
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ $t('settings.members.invite.title') }}</DialogTitle>
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
                    <Label for="invite-email">{{ $t('settings.members.invite.email') }}</Label>
                    <Input
                        id="invite-email"
                        name="email"
                        type="email"
                        :placeholder="trans('settings.members.invite.email_placeholder')"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="invite-role">{{ $t('settings.members.invite.role') }}</Label>
                    <Select v-model="inviteRole" name="role">
                        <SelectTrigger class="w-full">
                            <SelectValue :placeholder="trans('settings.members.invite.role_placeholder')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="WorkspaceRole.Member">{{ $t('settings.members.roles.member') }}</SelectItem>
                            <SelectItem :value="WorkspaceRole.Admin">{{ $t('settings.members.roles.admin') }}</SelectItem>
                            <SelectItem :value="WorkspaceRole.Viewer">{{ $t('settings.members.roles.viewer') }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <input type="hidden" name="role" :value="inviteRole" />
                    <InputError :message="errors.role" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="processing">
                        {{ $t('settings.members.invite.submit') }}
                    </Button>
                    <Button
                        variant="secondary"
                        type="button"
                        @click="open = false"
                    >
                        {{ $t('settings.members.cancel') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
