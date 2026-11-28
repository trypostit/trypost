import { useEcho } from '@laravel/echo-vue';

export const usePostEcho = <T = unknown>(
    postId: string,
    event: string | string[],
    callback: (payload: T) => void,
) => {
    return useEcho<T>(`post.${postId}`, event, callback);
};
