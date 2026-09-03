/**
 * HTTP verbs for the HTTP Request node. Mirrors the backend
 * App\Enums\Automation\HttpMethod enum — these values feed the node config
 * selects and must match the validation rules server-side.
 */
export const HttpMethod = {
    Get: 'GET',
    Post: 'POST',
    Put: 'PUT',
    Patch: 'PATCH',
    Delete: 'DELETE',
} as const;

export type HttpMethodValue = (typeof HttpMethod)[keyof typeof HttpMethod];

export const HTTP_METHODS: HttpMethodValue[] = Object.values(HttpMethod);

/** Verbs that carry a request body (mirror HttpMethod::withBody on the backend). */
export const HTTP_METHODS_WITH_BODY: HttpMethodValue[] = [HttpMethod.Post, HttpMethod.Put, HttpMethod.Patch];
