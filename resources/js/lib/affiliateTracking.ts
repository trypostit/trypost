import { filled } from '@/lib/helpers';

export interface AffiliateSignup {
    email: string;
    externalUserId: string;
}

/**
 * Records a new user as a lead of the affiliate who referred them. The server
 * flashes `affiliateSignup` once, after any signup (email, Google, GitHub), and
 * only while the Affonso pixel is configured; the pixel itself only creates a
 * lead when the browser holds an affiliate's referral cookie.
 *
 * The pixel loads with `async defer`, so on a full page load (an OAuth signup)
 * it may not be ready yet: the call then waits for its script to load.
 */
export const trackAffiliateSignup = (
    signup: AffiliateSignup | undefined,
): void => {
    if (!filled(signup)) {
        return;
    }

    if (filled(window.Affonso)) {
        window.Affonso.signup(signup);

        return;
    }

    const pixel = document.querySelector('script[data-affonso]');

    if (!filled(pixel)) {
        return;
    }

    pixel.addEventListener('load', () => window.Affonso?.signup(signup), {
        once: true,
    });
};
