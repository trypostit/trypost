import { execSync } from 'node:child_process';

import type { Plugin } from 'vite';

/** The full commit being built: the Forge deploy's, the Docker image's build arg, else git. */
const buildCommit = (): string => {
    if (process.env.FORGE_DEPLOY_COMMIT) {
        return process.env.FORGE_DEPLOY_COMMIT;
    }

    if (process.env.BUILD_COMMIT) {
        return process.env.BUILD_COMMIT;
    }

    try {
        return execSync('git rev-parse HEAD').toString().trim();
    } catch {
        return '';
    }
};

/** Bakes the commit and the build time into the bundle, shown at the bottom of the settings sidebar. */
export const buildInfo = (): Plugin => ({
    name: 'build-info',
    config: () => ({
        define: {
            __BUILD_COMMIT__: JSON.stringify(buildCommit()),
            __BUILD_DATE__: JSON.stringify(new Date().toISOString()),
        },
    }),
});
