import { filled } from '@/lib/helpers';

/** A link a toast offers next to its message: "View post" and where it goes. */
export type ToastLink = {
    label: string;
    url: string;
};

/** The options a toast takes for its link; both stay as keys without one, so they clear a toast still on screen. */
export type ToastLinkOptions = {
    action: { label: string; onClick: () => void } | undefined;
    classes: { toast: string; content: string } | undefined;
};

/**
 * What a toast adds when the flash carries a link: the link right after the
 * message, in a toast as wide as both. Without a link both options are still
 * set, to nothing: a toast on screen under the same id keeps every option it
 * is not given, so leaving them out would keep the previous toast's link.
 */
export const toastLinkOptions = (
    link: ToastLink | undefined,
    visit: (url: string) => void,
): ToastLinkOptions => {
    if (!filled(link)) {
        return { action: undefined, classes: undefined };
    }

    return {
        action: {
            label: link.label,
            onClick: () => visit(link.url),
        },
        classes: {
            toast: '!inset-x-0 !mx-auto !w-fit !max-w-(--width)',
            content: '!grow-0',
        },
    };
};
