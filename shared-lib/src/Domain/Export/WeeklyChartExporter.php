<?php

namespace CEL\Shared\Domain\Export;

/**
 * WeeklyEnrollmentChartExporter
 *
 * Renders a site-wise week-wise enrollment line chart using Chart.js.
 * All rendering logic lives in EnrollmentChartExporter.
 */
class WeeklyChartExporter extends ChartExporter
{
    public function __construct(
        bool   $inline  = false,
        string $cssPath = '/assets/css/weekly_enrollment.css',
        string $toolbar = ''
    ) 
    {
        parent::__construct($inline, $cssPath, $toolbar);
    }

	// protected bool $showTotalLine = false;  // only here when we do not want the totla line in the chart
    protected function periodsKey(): string     { return 'weeks'; }
    protected function canvasId(): string       { return 'weeklyChart'; }
    protected function axisLabel(): string      { return 'Week'; }
    protected function chartTitle(): string     { return 'Week-wise'; }
    protected function defaultCssPath(): string { return '/assets/css/weekly_enrollment.css'; }
}
