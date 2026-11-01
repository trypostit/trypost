/**
 * What the app keeps in this browser for a user, in every workspace, by name
 * (`area.thing`, like lang keys). An item never set is absent.
 */
export interface UserStorage {
    'mcp.promptDismissed': boolean;
}

/**
 * The value of a user's one localStorage key, `trypost:<user id>`: `user`
 * applies in every workspace, `workspaces` holds what only makes sense in one.
 */
export interface BrowserStorage {
    version: 1;
    user: Partial<UserStorage>;
    workspaces: Record<string, Record<string, unknown>>;
}
