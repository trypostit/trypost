/**
 * Mirror of `App\Support\Hashtags::PATTERN`; keep the two identical. No
 * lookbehind: the preceding character is consumed instead (Safari < 16.4).
 */
const HASHTAG_PATTERN =
    /(?:^|[^\p{L}\p{N}_&#/])#(?=[\p{N}_]*\p{L})[\p{L}\p{N}_]+/gu;

export const countHashtags = (text: string): number =>
    text.match(HASHTAG_PATTERN)?.length ?? 0;
