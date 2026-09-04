# GitLab CI/CD

GitLab reads Greenlight JUnit output through `artifacts:reports:junit`.
Greenlight does not need a GitLab-specific reporter.

## Run Greenlight

Use this job to run Greenlight without additional CI integration:

```yaml
tests:
  stage: test
  before_script:
    - composer install --no-interaction --prefer-dist
  script:
    - vendor/bin/greenlight run
```

This example assumes that the runner has PHP and Composer. Greenlight returns a
nonzero exit code when the run fails. GitLab then marks the job as failed.

The following sections add optional CI integrations.

## Configure attachment storage

Configure a project-relative parent directory for Greenlight run directories.
Add this configuration to `greenlight.php`:

<!-- php-example {"mode":"display","reason":"Shows a configuration call after omitted calls."} -->
```php
return GreenlightConfig::create()
    // ...
    ->artifacts(fn ($artifacts) => $artifacts
        ->directory('build/gitlab/greenlight-runs'));
```

Greenlight creates a unique run directory below this parent directory. Retained
attachments stay in that run directory. Upload the parent directory to keep
attachments after the job ends.

## Publish test results

Add a test job to `.gitlab-ci.yml`. This example assumes that the runner has
PHP and Composer:

```yaml
tests:
  stage: test
  before_script:
    - composer install --no-interaction --prefer-dist
  script:
    - vendor/bin/greenlight run --reporter=plain --reporter=junit=build/test-results/greenlight.xml
  artifacts:
    when: always
    expire_in: 1 week
    paths:
      - build/test-results/greenlight.xml
      - build/gitlab/greenlight-runs/
    reports:
      junit: build/test-results/greenlight.xml
```

The `plain` reporter keeps human-readable output in the job log. The `junit`
reporter writes the project-relative XML file that GitLab reads.

GitLab uploads report artifacts after successful and failed jobs. The
`when: always` value also uploads the browsable XML file and retained
attachments after a failed job. A job timeout can prevent this upload.

The `expire_in` value controls artifact retention. Select a period that
agrees with your project policy.

JUnit reports do not determine the job status. The Greenlight command returns
a nonzero exit code for a failed run. Do not discard that exit code.

See the GitLab documentation for [unit test
reports](https://docs.gitlab.com/ci/testing/unit_test_reports/) and [job
artifacts](https://docs.gitlab.com/ci/jobs/job_artifacts/).

## Use parallel shards

Use GitLab parallel jobs for a large suite. Pass the GitLab job index and total
to Greenlight:

```yaml
tests:
  stage: test
  parallel: 4
  before_script:
    - composer install --no-interaction --prefer-dist
  script:
    - vendor/bin/greenlight run --shard="$CI_NODE_INDEX/$CI_NODE_TOTAL" --reporter=plain --reporter="junit=build/test-results/greenlight-$CI_NODE_INDEX.xml"
  artifacts:
    when: always
    expire_in: 1 week
    paths:
      - build/test-results/greenlight-*.xml
      - build/gitlab/greenlight-runs/
    reports:
      junit: build/test-results/greenlight-*.xml
```

Each parallel job has a separate workspace and artifact archive. Greenlight
uses one-based shard numbers, which agree with `CI_NODE_INDEX`.

For multiple XML files in one job, `junit` accepts a filename pattern or an
array of paths. It does not accept a directory. See the GitLab
[`junit` report reference](https://docs.gitlab.com/ci/yaml/artifacts_reports/#artifactsreportsjunit)
and [parallel job variables](https://docs.gitlab.com/ci/variables/predefined_variables/).

## Add coverage annotations

Greenlight can write the Cobertura report that GitLab uses for merge request
diff annotations. Enable PCOV or Xdebug coverage mode in the test job. Add this
configuration to `greenlight.php`:

<!-- php-example {"mode":"display","reason":"Shows a configuration call after omitted calls."} -->
```php
return GreenlightConfig::create()
    // ...
    ->coverage(fn ($coverage) => $coverage
        ->include('src')
        ->requireDriver()
        ->export('cobertura', 'build/coverage/cobertura.xml'));
```

Add the coverage file to the test job artifacts:

```yaml
  artifacts:
    when: always
    expire_in: 1 week
    paths:
      - build/test-results/greenlight.xml
      - build/coverage/cobertura.xml
      - build/gitlab/greenlight-runs/
    reports:
      junit: build/test-results/greenlight.xml
      coverage_report:
        coverage_format: cobertura
        path: build/coverage/cobertura.xml
```

GitLab uses `coverage_report` for line annotations in a merge request diff. It
does not add a coverage percentage to the merge request widget.

A Cobertura export from one Greenlight shard contains only that shard's
coverage. For complete project coverage, use a separate unsharded coverage
job. In one job, GitLab can merge the Cobertura files that one pattern selects.

See the GitLab documentation for [coverage
visualization](https://docs.gitlab.com/ci/testing/code_coverage/coverage_visualization/).
