<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import MemberAccessFields from '@/components/members/MemberAccessFields.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { update as updateMember } from '@/routes/app/members';
import type { WorkspaceMember } from '@/types/members';

const props = defineProps<{ member: WorkspaceMember | null }>();

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const isAdmin = ref(false);
const requiresApproval = ref(false);

watch(
    () => props.member,
    (member) => {
        isAdmin.value = member?.is_admin ?? false;
        requiresApproval.value = member?.requires_approval ?? false;
    },
    { immediate: true },
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="edit-member-dialog">
            <DialogHeader>
                <DialogTitle>{{ $t('settings.members.edit.title') }}</DialogTitle>
                <DialogDescription>
                    {{ $t('settings.members.edit.description') }}
                </DialogDescription>
            </DialogHeader>
            <Form
                v-if="member"
                v-bind="updateMember.form(member.id)"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="closeDialog"
            >
                <div
                    class="flex items-center gap-3 rounded-xl border border-border p-3"
                >
                    <Avatar
                        :src="member.photo_url"
                        :name="member.name"
                        class="size-10 rounded-lg"
                        fallback-class="bg-sidebar-accent text-sidebar-accent-foreground"
                    />
                    <div class="flex min-w-0 flex-col">
                        <p class="truncate text-sm font-emphasis text-foreground">
                            {{ member.name }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ member.email }}
                        </p>
                    </div>
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
                        data-testid="edit-member-cancel"
                        @click="closeDialog"
                    >
                        {{ $t('settings.members.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-testid="edit-member-submit"
                    >
                        {{ $t('settings.members.edit.submit') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
