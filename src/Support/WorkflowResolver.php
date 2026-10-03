<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowPreset;
use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowStagePreset;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Reads and validates named workflow presets from config('approvals.workflows').
 *
 * An optional key that is not set — absent, null or blank (`''` or whitespace) — takes its
 * default; a present value of the wrong shape throws.
 */
final class WorkflowResolver
{
    public function resolve(string $name): WorkflowPreset
    {
        $workflows = self::presets();

        if (! array_key_exists($name, $workflows)) {
            throw UnknownWorkflowException::named($name);
        }

        $config = $workflows[$name];

        if (! is_array($config)) {
            throw UnknownWorkflowException::invalid($name, 'the preset must be an array.');
        }

        $expiry = $this->optionalInt($name, $config, 'expiry');

        if ($expiry !== null && $expiry < 1) {
            throw UnknownWorkflowException::invalid($name, '[expiry] must be a positive number of seconds.');
        }

        if (array_key_exists('stages', $config)) {
            return $this->resolveStaged($name, $config, $expiry);
        }

        return new WorkflowPreset(
            name: $name,
            rule: $this->rule($name, $config['rule'] ?? null),
            quorum: $this->optionalInt($name, $config, 'quorum'),
            requiredApprovers: $this->optionalInt($name, $config, 'required_approvers'),
            expiry: $expiry,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveStaged(string $name, array $config, ?int $expiry): WorkflowPreset
    {
        $rawStages = $config['stages'];

        if (! is_array($rawStages) || $rawStages === []) {
            throw UnknownWorkflowException::invalid($name, 'stages must be a non-empty array.');
        }

        $stages = [];

        foreach (array_values($rawStages) as $index => $raw) {
            if (! is_array($raw)) {
                throw UnknownWorkflowException::invalid($name, "stage #{$index} must be an array.");
            }

            $required = $raw['required_approvers'] ?? null;

            if (! is_int($required) || $required < 1) {
                throw UnknownWorkflowException::invalid(
                    $name,
                    "stage #{$index} must declare a positive integer required_approvers.",
                );
            }

            $stageName = self::isUnset($raw['name'] ?? null) ? null : $raw['name'];

            if ($stageName !== null && ! is_string($stageName)) {
                throw UnknownWorkflowException::invalid($name, "stage #{$index} name must be a string.");
            }

            $stages[] = new WorkflowStagePreset(
                rule: $this->rule($name, $raw['rule'] ?? null),
                requiredApprovers: $required,
                quorum: $this->optionalInt($name, $raw, 'quorum'),
                name: $stageName,
            );
        }

        return new WorkflowPreset(
            name: $name,
            stages: $stages,
            expiry: $expiry,
            rejectOnStageRejection: $this->rejectOnStageRejection($name, $config),
        );
    }

    /**
     * The `approvals.workflows` registry: no presets when not set, otherwise a name => preset
     * map — anything else throws rather than reading as "no presets".
     *
     * @return array<array-key, mixed>
     */
    public static function presets(): array
    {
        $workflows = config('approvals.workflows');

        if (self::isUnset($workflows)) {
            return [];
        }

        if (! is_array($workflows)) {
            throw new InvalidConfigurationException(
                'Configuration value [approvals.workflows] must be a name => preset map, ['.get_debug_type($workflows).'] given.',
            );
        }

        return $workflows;
    }

    /**
     * `reject_on_stage_rejection`: true when not set (absent, null or blank — blank is never
     * false); a bool or a boolean spelling (`'false'`, `0`, `'off'`, …) otherwise. Anything
     * else throws — it used to read every value but a literal `false` as true.
     *
     * @param  array<string, mixed>  $config
     */
    private function rejectOnStageRejection(string $name, array $config): bool
    {
        $value = $config['reject_on_stage_rejection'] ?? null;

        if (self::isUnset($value)) {
            return true;
        }

        $parsed = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;

        return $parsed ?? throw UnknownWorkflowException::invalid($name, '[reject_on_stage_rejection] must be a boolean.');
    }

    /**
     * A rule: unanimous when not set, otherwise a case or its exact value.
     */
    private function rule(string $name, mixed $value): ApprovalRule
    {
        if (self::isUnset($value)) {
            return ApprovalRule::Unanimous;
        }

        if ($value instanceof ApprovalRule) {
            return $value;
        }

        if (is_string($value) && ($rule = ApprovalRule::tryFrom($value)) !== null) {
            return $rule;
        }

        throw UnknownWorkflowException::invalid($name, 'an unknown rule was given.');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionalInt(string $name, array $config, string $key): ?int
    {
        if (self::isUnset($config[$key] ?? null)) {
            return null;
        }

        $value = $config[$key];

        if (! is_int($value)) {
            throw UnknownWorkflowException::invalid($name, "[{$key}] must be an integer.");
        }

        return $value;
    }

    /**
     * Not set: null or blank (`''` or whitespace), read exactly like an absent key.
     */
    private static function isUnset(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
