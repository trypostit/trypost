import { ref, watch } from 'vue';

/** Mirrors `InstagramCollaborators::USERNAME_PATTERN` after a leading `@` is stripped. */
const USERNAME_PATTERN = /^(?!.*\.\.)(?!\.)[A-Za-z0-9._]{1,30}(?<!\.)$/;

export const getUsername = (value?: string | null): string =>
    (value ?? '').trim().replace(/^@+/, '');

const key = (value?: string | null): string => getUsername(value).toLowerCase();

export const isValidUsername = (value?: string | null): boolean => USERNAME_PATTERN.test(getUsername(value));

export const isSameUsername = (left?: string | null, right?: string | null): boolean => {
    const username = key(left);

    return username !== '' && username === key(right);
};

export const formatUsername = (value?: string | null): string => {
    const username = getUsername(value);

    return username ? `@${username}` : '';
};

export const useUsername = (
    usernames: () => string[],
    ownUsername: () => string | undefined | null,
    onChange: (usernames: string[]) => void,
) => {
    const draft = ref('');
    const rejection = ref<'self' | 'duplicate' | 'invalid' | null>(null);

    watch(draft, () => {
        rejection.value = null;
    });

    const add = () => {
        const username = getUsername(draft.value);
        const current = usernames();

        if (username === '') {
            rejection.value = null;

            return;
        }

        if (!isValidUsername(username)) {
            rejection.value = 'invalid';

            return;
        }

        if (isSameUsername(username, ownUsername())) {
            rejection.value = 'self';

            return;
        }

        if (current.some((item) => isSameUsername(item, username))) {
            rejection.value = 'duplicate';

            return;
        }

        draft.value = '';
        onChange([...current, username]);
    };

    const remove = (username: string) => onChange(usernames().filter((item) => item !== username));

    return { draft, rejection, add, remove };
};
