<?php

namespace App\Services\TaskEngine;

class TaskAutomationSettings
{
    public function resolve(?array $workspaceSettings): array
    {
        $taskSettings = (array) (($workspaceSettings ?? [])['taskAutomation'] ?? []);

        return $this->sanitize(array_replace_recursive($this->defaults(), $taskSettings));
    }

    public function defaults(): array
    {
        return [
            'version' => 1,
            'settings_edit_scope' => 'admins',
            'daily_outreach_capacity' => 12,
            'max_active_tasks' => 18,
            'time_pressure_active_task_limit' => 32,
            'max_new_tasks_per_generation' => 12,
            'follow_up_delay_days' => 5,
            'aggressive_follow_up_delay_days' => 2,
            'reply_check_in_delay_days' => 2,
            'external_check_in_days' => 3,
            'negotiation_check_in_delay_days' => 3,
            'accepted_follow_up_delay_days' => 7,
            'max_follow_up_attempts' => 2,
            'high_value_warmup_enabled' => true,
            'high_value_threshold' => 75,
            'medium_value_threshold' => 50,
            'warmup_gap_hours' => 12,
            'time_pressure_mode' => false,
            'archive_snooze_days' => 30,
            'revival_review_enabled' => true,
            'revival_review_interval_days' => 90,
        ];
    }

    public function sanitize(array $settings): array
    {
        $defaults = $this->defaults();
        $merged = array_replace_recursive($defaults, $settings);

        $ints = [
            'version',
            'daily_outreach_capacity',
            'max_active_tasks',
            'time_pressure_active_task_limit',
            'max_new_tasks_per_generation',
            'follow_up_delay_days',
            'aggressive_follow_up_delay_days',
            'reply_check_in_delay_days',
            'external_check_in_days',
            'negotiation_check_in_delay_days',
            'accepted_follow_up_delay_days',
            'max_follow_up_attempts',
            'high_value_threshold',
            'medium_value_threshold',
            'warmup_gap_hours',
            'archive_snooze_days',
            'revival_review_interval_days',
        ];

        foreach ($ints as $key) {
            $merged[$key] = max(0, (int) ($merged[$key] ?? $defaults[$key]));
        }

        $merged['high_value_warmup_enabled'] = (bool) ($merged['high_value_warmup_enabled'] ?? $defaults['high_value_warmup_enabled']);
        $merged['time_pressure_mode'] = (bool) ($merged['time_pressure_mode'] ?? $defaults['time_pressure_mode']);
        $merged['revival_review_enabled'] = (bool) ($merged['revival_review_enabled'] ?? $defaults['revival_review_enabled']);
        $merged['settings_edit_scope'] = in_array(($merged['settings_edit_scope'] ?? 'admins'), ['admins', 'all_seats'], true)
            ? $merged['settings_edit_scope']
            : 'admins';

        return $merged;
    }

    public function canEdit(array $settings, ?string $role): bool
    {
        $role = strtolower(trim((string) $role));
        if ($role === 'owner' || $role === 'admin') {
            return true;
        }

        return ($settings['settings_edit_scope'] ?? 'admins') === 'all_seats' && $role === 'member';
    }

    public function timePressureEnabled(array $settings): bool
    {
        return (bool) ($settings['time_pressure_mode'] ?? false);
    }
}
