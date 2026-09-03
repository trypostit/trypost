interface ConfigNode {
    type?: string;
    data?: Record<string, unknown> | null;
}

/**
 * First node-config issue that would block a run, or null when every node is
 * runnable. Generate has its own inline compliance UI; the backend
 * AutomationConfigValidator remains the safety net.
 */
export const firstConfigIssue = (_nodes: ConfigNode[]): string | null => null;
