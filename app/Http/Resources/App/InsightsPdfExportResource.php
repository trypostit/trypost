<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InsightsPdfExportResource extends JsonResource
{
    public function __construct(private readonly string $exportId, mixed $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: string, status: string, download_url: string|null}
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
        ];
    }
}
