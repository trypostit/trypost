import { injectDialogRootContext } from 'reka-ui';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const openDialogs = ref(0);

/**
 * Whether a dialog, alert dialog or sheet of `components/ui` is open on the
 * page, so a tour waits for it to close instead of opening over it.
 */
export const isDialogBlockingTours = computed(() => openDialogs.value > 0);

/**
 * Tells the tours while the dialog this content belongs to is open, read
 * from the dialog's own state. Called by the content component of every
 * dialog in `components/ui` (dialog, alert dialog, sheet); only the tours
 * read it (`isDialogBlockingTours`).
 */
export const trackDialogForTours = (): void => {
    const { open } = injectDialogRootContext();
    let counted = false;

    const sync = (isOpen: boolean): void => {
        if (isOpen === counted) {
            return;
        }

        counted = isOpen;

        if (isOpen) {
            openDialogs.value += 1;

            return;
        }

        openDialogs.value -= 1;
    };

    watch(open, sync, { immediate: true });
    onBeforeUnmount(() => sync(false));
};
