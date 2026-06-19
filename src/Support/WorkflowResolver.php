<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowPreset;
use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowStagePreset;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException;

/**
 * Reads and validates named workflow presets from config('approvals.workflows').
 */
final class WorkflowResolver
{
    public function resolve(string $name): WorkflowPreset
    {
        $workflows = config('approvals.workflows');

        if (! is_array($workflows) || ! array_key_exists($name, $workflows)) {
            throw UnknownWorkflowException::named($name);
        }

        $config = $workflows[$name];

        if (! is_array($config)) {
            throw UnknownWorkflowException::invalid($name, 'the preset must be an array.');
        }

        $expiry = $this->optionalInt($name, $config, 'expiry');

        if (array_key_exists('stages', $config)) {
            return $this->resolveStaged($name, $config, $expiry);
        }

        return new WorkflowPreset(
            name: $name,
            rule: $this->rule($name, $config['rule'] ?? ApprovalRule::Unanimous->value),
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

            $stages[] = new WorkflowStagePreset(
                rule: $this->rule($name, $raw['rule'] ?? ApprovalRule::Unanimous->value),
                requiredApprovers: $required,
                quorum: $this->optionalInt($name, $raw, 'quorum'),
                name: is_string($raw['name'] ?? null) ? $raw['name'] : null,
            );
        }

        return new WorkflowPreset(
            name: $name,
            stages: $stages,
            expiry: $expiry,
            rejectOnStageRejection: ($config['reject_on_stage_rejection'] ?? true) !== false,
        );
    }

    private function rule(string $name, mixed $value): ApprovalRule
    {
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
        if (! array_key_exists($key, $config) || $config[$key] === null) {
            return null;
        }

        $value = $config[$key];

        if (! is_int($value)) {
            throw UnknownWorkflowException::invalid($name, "[{$key}] must be an integer.");
        }

        return $value;
    }
}
