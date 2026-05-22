<?php

declare(strict_types=1);

namespace Library\Command\Concerns;

trait PluginCommandOutput
{
    /**
     * @param array<string,mixed> $report
     */
    private function outputPluginReport(array $report, bool $json): void
    {
        if ($json) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return;
        }

        foreach ($report as $key => $value) {
            if (is_array($value)) {
                $this->line($key . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE));
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $this->line($key . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value));
        }
    }
}
