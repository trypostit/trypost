<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Insights;

use App\Http\Requests\AnalyticsReportRequest;

/**
 * Validates an Insights export with the same range rules as the page; channel and label filters are
 * resolved against the workspace and silently drop anything that does not belong to it.
 */
class DownloadInsightsRequest extends AnalyticsReportRequest {}
