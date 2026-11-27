<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import DatePicker from '@/components/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { filled, isNumber } from '@/lib/helpers';
import { store as offersStore, update as offersUpdate } from '@/routes/admin/billing/offers';
import type { AdminOffer } from '@/types/admin';

/** Creates an offer, or edits the one given. The slug never changes once created: links and copy are keyed to it. */
const props = defineProps<{
    offer: AdminOffer | null;
    /** Today in the admin's zone, a new offer's first day by default. */
    today: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const SWITCHES: { field: 'requires_card' | 'first_subscription_only' | 'allow_promotion_codes' | 'show_banner'; label: string; hint: string }[] = [
    {
        field: 'requires_card',
        label: 'Asks for a card',
        hint: 'Off with a trial: the trial starts without Stripe, and the plan is charged only when they subscribe.',
    },
    {
        field: 'first_subscription_only',
        label: 'First subscription only',
        hint: 'Skips accounts that were ever billed or already redeemed an offer.',
    },
    {
        field: 'allow_promotion_codes',
        label: 'Promotion code field',
        hint: 'Shows the field in Checkout. Not with a coupon: Stripe takes one or the other.',
    },
    {
        field: 'show_banner',
        label: 'Shows the banner',
        hint: 'The Welcome plan step shows the offer’s name and headline.',
    },
];

const blankForm = () => ({
    slug: '',
    stripe_coupon_id: '',
    trial_days: '',
    starts_at: props.today,
    ends_at: '',
    max_redemptions: '',
    requires_card: true,
    first_subscription_only: false,
    allow_promotion_codes: false,
    show_banner: false,
});

const form = useForm(blankForm());

const isEditing = computed(() => filled(props.offer));

const title = computed((): string => {
    if (filled(props.offer)) {
        return `Edit ${props.offer.slug}`;
    }

    return 'New offer';
});

const submitLabel = computed((): string => {
    if (isEditing.value) {
        return 'Save offer';
    }

    return 'Create offer';
});

const asText = (value: number | null): string => {
    if (isNumber(value)) {
        return String(value);
    }

    return '';
};

/** Fills the form from the offer being edited, or empties it for a new one, each time the dialog opens. */
/** The form's values for the offer being edited, its empty fields as empty text. */
const offerForm = (offer: AdminOffer): ReturnType<typeof blankForm> => ({
    slug: offer.slug,
    stripe_coupon_id: offer.stripe_coupon_id ?? '',
    trial_days: asText(offer.trial_days),
    starts_at: offer.starts_on ?? '',
    ends_at: offer.ends_on ?? '',
    max_redemptions: asText(offer.max_redemptions),
    requires_card: offer.requires_card,
    first_subscription_only: offer.first_subscription_only,
    allow_promotion_codes: offer.allow_promotion_codes,
    show_banner: offer.show_banner,
});

const formValues = (): ReturnType<typeof blankForm> => {
    if (filled(props.offer)) {
        return offerForm(props.offer);
    }

    return blankForm();
};

const fill = (): void => {
    form.clearErrors();
    form.defaults(formValues());
    form.reset();
};

/** Runs after the parent hands over the offer to edit, so a new offer never opens with the last one's values. */
watch(
    open,
    (isOpen) => {
        if (isOpen) {
            fill();
        }
    },
    { flush: 'post' },
);

const hasEnd = computed(() => filled(form.ends_at));

const setStartsAt = (day: string | null): void => {
    form.starts_at = day ?? '';
};

const setEndsAt = (day: string | null): void => {
    form.ends_at = day ?? '';
};

const clearEndsAt = (): void => {
    form.ends_at = '';
};

const closeDialog = (): void => {
    open.value = false;
};

const submit = (): void => {
    const options = { preserveScroll: true, onSuccess: closeDialog };

    if (filled(props.offer)) {
        form.put(offersUpdate.url(props.offer.id), options);

        return;
    }

    form.post(offersStore.url(), options);
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent data-testid="admin-offer-dialog" class="gap-0 p-0 sm:max-w-xl">
            <form @submit.prevent="submit">
                <div class="flex flex-col gap-1.5 px-8 pt-7 pb-5">
                    <DialogTitle class="font-sans text-base font-emphasis">{{ title }}</DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        Its copy lives in lang/&lt;locale&gt;/offers.php and ships with a deploy.
                        Ads and emails link to /register?offer=slug.
                    </DialogDescription>
                </div>

                <div class="flex max-h-[60dvh] flex-col gap-5 overflow-y-auto px-8 pb-6">
                    <div v-if="!isEditing" class="flex flex-col gap-2">
                        <Label for="offer-slug">Slug</Label>
                        <Input id="offer-slug" v-model="form.slug" placeholder="e.g. spring-sale" data-testid="admin-offer-slug" />
                        <InputError :message="form.errors.slug" />
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label for="offer-coupon">Stripe coupon</Label>
                            <Input id="offer-coupon" v-model="form.stripe_coupon_id" placeholder="e.g. SPRING50" data-testid="admin-offer-coupon" />
                            <InputError :message="form.errors.stripe_coupon_id" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="offer-trial">Trial days</Label>
                            <Input id="offer-trial" v-model="form.trial_days" type="number" placeholder="None" data-testid="admin-offer-trial" />
                            <InputError :message="form.errors.trial_days" />
                        </div>
                        <div class="flex flex-col gap-2" data-testid="admin-offer-starts">
                            <Label>Starts on</Label>
                            <DatePicker
                                :model-value="form.starts_at"
                                :show-time="false"
                                align="start"
                                placeholder="Pick a day"
                                @update:model-value="setStartsAt"
                            />
                            <InputError :message="form.errors.starts_at" />
                        </div>
                        <div class="flex flex-col gap-2" data-testid="admin-offer-ends">
                            <div class="flex items-center justify-between">
                                <Label>Ends on</Label>
                                <button
                                    v-if="hasEnd"
                                    type="button"
                                    class="text-xs text-muted-foreground hover:text-foreground"
                                    data-testid="admin-offer-ends-clear"
                                    @click="clearEndsAt"
                                >
                                    No end
                                </button>
                            </div>
                            <DatePicker
                                :model-value="form.ends_at"
                                :show-time="false"
                                align="start"
                                placeholder="No end"
                                @update:model-value="setEndsAt"
                            />
                            <InputError :message="form.errors.ends_at" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="offer-limit">Redemption limit</Label>
                            <Input id="offer-limit" v-model="form.max_redemptions" type="number" placeholder="No limit" data-testid="admin-offer-limit" />
                            <InputError :message="form.errors.max_redemptions" />
                        </div>
                    </div>

                    <ul class="flex flex-col divide-y divide-border rounded-xl border border-border">
                        <li v-for="item in SWITCHES" :key="item.field" class="flex items-start gap-3 px-4 py-3">
                            <div class="grid min-w-0 flex-1 gap-0.5">
                                <Label :for="`offer-${item.field}`" class="text-sm">{{ item.label }}</Label>
                                <span class="text-xs text-muted-foreground">{{ item.hint }}</span>
                                <InputError :message="form.errors[item.field]" />
                            </div>
                            <Switch
                                :id="`offer-${item.field}`"
                                v-model="form[item.field]"
                                :data-testid="`admin-offer-${item.field}`"
                            />
                        </li>
                    </ul>
                </div>

                <DialogFooter class="mx-2 mb-2">
                    <Button variant="ghost" type="button" @click="closeDialog">Cancel</Button>
                    <Button type="submit" :loading="form.processing" data-testid="admin-offer-submit">
                        {{ submitLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
