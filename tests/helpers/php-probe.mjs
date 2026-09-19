import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));

export function phpJson(program) {
    const result = spawnSync(
        process.env.PHP_BIN || 'php',
        ['-r', program],
        {
            cwd: root,
            encoding: 'utf8',
            timeout: 15000
        }
    );

    if (result.error) {
        throw result.error;
    }

    if (result.status !== 0) {
        throw new Error(
            result.stderr
            || result.stdout
            || `PHP exit ${result.status}`
        );
    }

    return JSON.parse(result.stdout);
}
