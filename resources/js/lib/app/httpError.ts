export const extractErrorMessage = (error: unknown): string | null => {
    const data = (error as { response?: { data?: string } })?.response?.data;
    if (typeof data !== 'string') return null;
    try {
        return JSON.parse(data)?.message ?? null;
    } catch {
        return null;
    }
};
