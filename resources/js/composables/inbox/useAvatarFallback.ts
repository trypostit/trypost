import { reactive } from 'vue';

const failed = reactive(new Set<string>());
const checked = new Set<string>();

/**
 * A participant's picture, or null once it fails to load, so the avatar shows
 * the initial instead of a broken image. Instagram profile pictures expire
 * after a few days.
 */
export const useAvatarFallback = () => {
    const avatarSrc = (url: string | null | undefined): string | null => {
        if (!url || failed.has(url)) {
            return null;
        }

        if (!checked.has(url)) {
            checked.add(url);

            const probe = new Image();
            probe.onerror = () => failed.add(url);
            probe.src = url;
        }

        return url;
    };

    return { avatarSrc };
};
