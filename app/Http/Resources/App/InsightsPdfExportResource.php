<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Workspace;
use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsightsPdfExportResource extends JsonResource
{
    /**
     * @param  Workspace|null  $workspace  Given when the export starts, to hand back the cover logo it saved.
     */
    public function __construct(private readonly string $exportId, mixed $resource, private readonly ?Workspace $workspace = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = (string) data_get($this->resource, 'status');

        return [
            'id' => $this->exportId,
            'status' => $status,
            'download_url' => $status === InsightsPdfExports::READY
                ? route('app.insights.pdf.download', $this->exportId)
                : null,
            $this->mergeWhen(filled($this->workspace), fn (): array => [
                'report_logo_url' => $this->workspace->getFirstMediaUrl('report_logo') ?: null,
            ]),
        ];
    }
}
