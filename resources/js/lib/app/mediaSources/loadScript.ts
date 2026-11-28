const loaded = new Map<string, Promise<void>>();

/** Loads a provider SDK once per `src`; a failed load can be retried. */
export const loadScript = (
    src: string,
    attrs: Record<string, string> = {},
): Promise<void> => {
    const existing = loaded.get(src);
    if (existing) return existing;

    const promise = new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        Object.entries(attrs).forEach(([name, value]) =>
            script.setAttribute(name, value),
        );
        script.addEventListener('load', () => resolve());
        script.addEventListener('error', () => {
            loaded.delete(src);
            script.remove();
            reject(new Error(`Failed to load ${src}`));
        });
        document.head.appendChild(script);
    });

    loaded.set(src, promise);

    return promise;
};
