import { spawnSync } from 'node:child_process';

const cases = [
  ['feat(expect): add matcher aliases', true],
  ['fix(execution-process-orchestrator): close worker sockets', true],
  ['ci: synchronize repository labels', true],
  ['feat(runner): add a scheduling option', false],
  ['fix(Coverage): correct export paths', false],
];

for (const [message, expected] of cases) {
  const result = spawnSync(
    'npx',
    ['--yes', '--package=@commitlint/cli@21.2.2', '--', 'commitlint', '--verbose'],
    {
      encoding: 'utf8',
      input: `${message}\n`,
    },
  );
  const valid = result.status === 0;

  if (valid !== expected) {
    process.stderr.write(result.stdout);
    process.stderr.write(result.stderr);
    throw new Error(`Commitlint returned an unexpected result for "${message}".`);
  }
}
