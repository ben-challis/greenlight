<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\After;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tempest\TempestBridgeError;
use Greenlight\Tempest\TempestPlugin;
use Greenlight\Tests\Support\PluginLifecycle;
use Greenlight\Tests\Support\ServiceResolverProbe;
use Tempest\Container\GenericContainer;
use Tempest\Container\Tag;
use Tempest\Core\FrameworkKernel;
use Tempest\Http\GenericRequest;
use Tempest\Http\Request;

#[SkipUnless(ClassAvailable::class, FrameworkKernel::class)]
final class TempestPluginLifecycleTest
{
    /** @var list<TempestPlugin> */
    private array $plugins = [];

    private int $projectNumber = 0;

    public function __construct(
        private readonly EnvironmentVariables $environment,
        private readonly TemporaryDirectory $tempDirectory,
    ) {}

    /** A failed expectation MUST NOT leave an active Tempest kernel in the worker. */
    #[After]
    public function releaseKernels(): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->afterTest($this->context(), $this->result());
        }

        $this->plugins = [];
    }

    #[Test]
    public function resolvesATaggedContainerService(): void
    {
        $plugin = $this->plugin();
        $kernel = $this->kernel($plugin);
        $expected = new TaggedProbeImplementation();
        $kernel->container->singleton(TaggedProbe::class, $expected, 'preferred');

        Expect::that($plugin->resolve(TaggedProbe::class, [new Tag('preferred')]))
            ->because('the Tempest Tag attribute MUST select the tagged container binding')
            ->toBe($expected);
    }

    #[Test]
    public function fallbackResolverRunsBeforeTerminalTempestResolver(): void
    {
        $answer = new TaggedProbeImplementation();
        $fallback = new ServiceResolverProbe($answer);
        $resolvers = new PluginRegistry([$this->plugin(), $fallback])->serviceResolvers();
        $scopes = new HarnessScopes(new HarnessRegistry(), $resolvers);

        Expect::that($scopes->resolve(TaggedProbe::class, 'test'))
            ->because('a fallback resolver MUST run before the terminal Tempest resolver')
            ->toBe($answer);
        Expect::that($fallback->calls)->toBe(1);
    }

    #[Test]
    public function wrapsContainerResolutionFailures(): void
    {
        $plugin = $this->plugin();
        $this->kernel($plugin);

        Expect::that(static fn(): object => $plugin->resolve(MissingProbe::class, []))
            ->toThrow(
                TempestBridgeError::class,
                matching: '/^The Tempest container could not resolve the parameter type "'
                    . \preg_quote(MissingProbe::class, '/')
                    . '":/',
            );
    }

    #[Test]
    public function preservesBridgeFailuresDuringResolution(): void
    {
        $missingRoot = $this->tempDirectory->path() . '/missing-resolution-root';
        $plugin = new TempestPlugin($missingRoot);

        Expect::that(static fn(): object => $plugin->resolve(MissingProbe::class, []))
            ->toThrow(
                TempestBridgeError::class,
                matching: '/^TempestPlugin could not boot the application at "'
                    . \preg_quote($missingRoot, '/')
                    . '": /',
            );
    }

    #[Test]
    public function preparesANewBaseRequestForTheNextTest(): void
    {
        $plugin = $this->plugin();
        $kernel = $this->kernel($plugin);
        $plugin->afterTest($this->context(), $this->result());

        $plugin->beforeTest($this->context());

        Expect::that($kernel->container->get(Request::class))
            ->because('each Tempest test MUST receive a new base request after the container reset')
            ->toBeInstanceOf(GenericRequest::class);
    }

    #[Test]
    public function rejectsAContainerServiceOfTheWrongType(): void
    {
        $plugin = $this->plugin();
        $kernel = $this->kernel($plugin);
        $kernel->container->singleton(MissingProbe::class, new \stdClass());

        Expect::that(static fn(): object => $plugin->resolve(MissingProbe::class, []))
            ->toThrow(
                TempestBridgeError::class,
                message: 'The Tempest container returned "stdClass" for the parameter type "'
                    . MissingProbe::class
                    . '".',
            );
    }

    #[Test]
    public function bootFailuresRestoreTheProcessEnvironment(): void
    {
        $this->environment->set('ENVIRONMENT', 'before-tempest');
        $missingRoot = $this->tempDirectory->path() . '/missing';
        $plugin = new TempestPlugin($missingRoot);
        $factory = $plugin->services()[0]->factory;

        Expect::that(static fn(): object => $factory())
            ->toThrow(
                TempestBridgeError::class,
                matching: '/^TempestPlugin could not boot the application at "'
                    . \preg_quote($missingRoot, '/')
                    . '": /',
            );
        Expect::that(\getenv('ENVIRONMENT'))->toBe('before-tempest');
        Expect::that($_ENV['ENVIRONMENT'])->toBe('before-tempest');
        Expect::that($_SERVER['ENVIRONMENT'])->toBe('before-tempest');
    }

    #[Test]
    public function shutdownFailuresRestoreTheProcessEnvironment(): void
    {
        $this->environment->set('ENVIRONMENT', 'before-tempest');
        $plugin = $this->plugin();
        $kernel = $this->kernel($plugin);
        $kernel->container->singleton('Tempest\EventBus\EventBus', new \stdClass());

        Expect::that(fn(): TestResult => $plugin->afterTest($this->context(), $this->result()))
            ->toThrow(
                TempestBridgeError::class,
                matching: '/^TempestPlugin could not shut down the application/',
            );
        Expect::that(\getenv('ENVIRONMENT'))->toBe('before-tempest');
        Expect::that($_ENV['ENVIRONMENT'])->toBe('before-tempest');
        Expect::that($_SERVER['ENVIRONMENT'])->toBe('before-tempest');
        Expect::that(GenericContainer::instance())->not()->toBe($kernel->container);
    }

    private function plugin(): TempestPlugin
    {
        $root = $this->tempDirectory->subdirectory('tempest-plugin-' . ++$this->projectNumber);
        $repository = \dirname(__DIR__, 3);
        $composer = <<<'JSON'
            {
                "name": "greenlight/tempest-unit-probe",
                "require": {
                    "tempest/framework": "^3.18"
                }
            }
            JSON;

        if (\file_put_contents($root . '/composer.json', $composer) === false) {
            Fail::because('Expected to write the Tempest test project composer.json file.');
        }

        if (!\symlink($repository . '/vendor', $root . '/vendor')) {
            Fail::because('Expected to link the Tempest test project vendor directory.');
        }

        $plugin = new TempestPlugin($root);
        $this->plugins[] = $plugin;

        return $plugin;
    }

    private function kernel(TempestPlugin $plugin): FrameworkKernel
    {
        $factory = $plugin->services()[0]->factory;
        $kernel = $factory();

        Expect::that($kernel)
            ->because('the Tempest kernel factory MUST return FrameworkKernel')
            ->toBeInstanceOf(FrameworkKernel::class);

        return $kernel;
    }

    private function context(): TestContext
    {
        return PluginLifecycle::context();
    }

    private function result(): TestResult
    {
        return PluginLifecycle::passedResult();
    }
}

interface MissingProbe {}

interface TaggedProbe {}

final readonly class TaggedProbeImplementation implements TaggedProbe {}
