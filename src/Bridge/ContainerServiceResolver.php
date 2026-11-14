<?php

declare(strict_types=1);

namespace Greenlight\Bridge;

use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceResolutionFailed;

/**
 * Resolves a test parameter from a framework container.
 *
 * `resolve()` uses the ID of the last `#[Service]` attribute. Without an ID,
 * it uses the parameter type. If the container does not have the service, the
 * resolver returns `null`. If a `#[Service]` attribute is present, it throws
 * the bridge error for an unknown ID.
 *
 * A container failure becomes the bridge resolution error, with the failure as
 * the previous exception. A `ServiceResolutionFailed` from the container
 * callbacks passes through without change.
 *
 * @internal
 */
final class ContainerServiceResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param class-string $type
     * @param list<object> $attributes
     * @param \Closure(string): bool $has
     * @param \Closure(string): mixed $get
     * @param class-string<ContainerServiceErrors> $errors
     * @throws ServiceResolutionFailed
     */
    public static function resolve(
        string $type,
        array $attributes,
        \Closure $has,
        \Closure $get,
        string $errors,
    ): ?object {
        $id = $type;
        $explicit = false;

        foreach ($attributes as $attribute) {
            if ($attribute instanceof Service) {
                $id = $attribute->id ?? $type;
                $explicit = true;
            }
        }

        try {
            $hasService = $has($id);
        } catch (ServiceResolutionFailed $error) {
            throw $error;
        } catch (\Throwable $cause) {
            throw $errors::serviceResolutionFailed($id, $type, $cause, ContainerCall::Has);
        }

        if (!$hasService) {
            if ($explicit) {
                throw $errors::unknownServiceId($id, $type);
            }

            return null;
        }

        try {
            $service = $get($id);
        } catch (ServiceResolutionFailed $error) {
            throw $error;
        } catch (\Throwable $cause) {
            throw $errors::serviceResolutionFailed($id, $type, $cause, ContainerCall::Get);
        }

        if (!$service instanceof $type) {
            throw $errors::serviceTypeMismatch($id, $type, $service);
        }

        return $service;
    }
}
