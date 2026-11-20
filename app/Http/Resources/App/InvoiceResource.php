<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Cashier\Invoice;

/**
 * A paid invoice on the billing page's invoices tab.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array{id: string, date: string, total: string, status: string, invoice_pdf: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date()->toIso8601String(),
            'total' => $this->total(),
            'status' => $this->status,
            'invoice_pdf' => $this->invoice_pdf,
        ];
    }
}
