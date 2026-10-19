<?php

namespace App\Services\Finance;

use App\Models\Finance\Import;
use App\Models\Finance\Order;
use App\Models\Product;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;

/**
 * A Plug&Pay order export (XLSX or CSV, one row per order line) uploaded by hand, next to the automatic API sync.
 * Lines are matched on order and SKU: an order the API already fetched only gets its payment status updated, other
 * lines are stored as "{order}#sku-{sku}-{n}" and replaced by the API lines once the sync fetches that order.
 */
class OrderFileImporter
{
    /**
     * The columns the export must have.
     *
     * @var list<string>
     */
    public const RequiredColumns = ['order_id', 'invoice_number', 'amount', 'payment_status'];

    /**
     * @return array{total: int, orders: int, new: int, updated: int, unchanged: int, skipped: int, paid: int, paid_ex_vat_cents: int, date_from: ?string, date_to: ?string, unknown_skus: list<string>}
     */
    public function preview(string $path, string $filename): array
    {
        return $this->run($path, $filename, null, dryRun: true);
    }

    /**
     * @return array{import: Import, total: int, orders: int, new: int, updated: int, unchanged: int, skipped: int, paid: int, paid_ex_vat_cents: int, date_from: ?string, date_to: ?string, unknown_skus: list<string>}
     */
    public function commit(string $path, string $filename, ?User $uploader = null): array
    {
        $result = DB::transaction(fn (): array => $this->run($path, $filename, $uploader, dryRun: false));

        app(PaymentMatcher::class)->matchIncoming();

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function run(string $path, string $filename, ?User $uploader, bool $dryRun): array
    {
        $lines = static::lines(static::read($path, $filename));
        $products = Product::query()->orderByRaw('deleted_in_plug_and_pay_at is not null')->orderByDesc('in_use')->get(['plug_and_pay_product_id', 'sku'])->unique('sku')->pluck('plug_and_pay_product_id', 'sku');
        $existing = Order::query()->whereIn('plug_and_pay_order_id', $lines->pluck('orderId')->unique())->get()->groupBy('plug_and_pay_order_id');
        $import = null;

        if (! $dryRun && $lines->isNotEmpty()) {
            $import = Import::create([
                'kind' => 'orders',
                'filename' => $filename,
                'file_hash' => hash_file('sha256', $path),
                'format' => str_ends_with(strtolower($filename), '.xlsx') ? 'plug-and-pay-xlsx' : 'plug-and-pay-csv',
                'rows_total' => $lines->count(),
                'date_from' => $lines->min('invoicedOn'),
                'date_to' => $lines->max('invoicedOn'),
                'uploaded_by' => $uploader?->getKey(),
            ]);
        }

        $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($lines->groupBy('orderId') as $orderId => $orderLines) {
            $current = $existing->get($orderId, collect());
            $fromApi = $current->reject(fn (Order $order): bool => str_contains($order->external_id, '#sku-'));
            $seen = [];

            foreach ($orderLines as $line) {
                $seen[$line['sku']] = ($seen[$line['sku']] ?? 0) + 1;
                $status = ['status' => $line['status'], 'paid_on' => $line['paidOn']];

                // The API already has this order: find the line by SKU and only take over the payment status.
                if ($fromApi->isNotEmpty()) {
                    $match = $fromApi->where('sku', $line['sku'])->values()->get($seen[$line['sku']] - 1);
                    $targets = $match ? collect([$match]) : $fromApi;
                    $changed = $targets->contains(fn (Order $order): bool => $order->status !== $line['status'] || $order->paid_on?->toDateString() !== $line['paidOn']);
                    $counts[$changed ? 'updated' : 'unchanged']++;

                    if ($changed && ! $dryRun) {
                        $targets->each(fn (Order $order) => $order->update($status));
                    }

                    continue;
                }

                $externalId = "{$orderId}#sku-".($line['sku'] ?: 'none')."-{$seen[$line['sku']]}";
                $record = $current->firstWhere('external_id', $externalId);
                $fields = [
                    'plug_and_pay_order_id' => $orderId,
                    'order_ref' => $line['ref'],
                    'plug_and_pay_product_id' => $line['sku'] !== '' ? ($products[$line['sku']] ?? null) : null,
                    'sku' => $line['sku'] !== '' ? $line['sku'] : null,
                    'product_name' => $line['product'],
                    'quantity' => $line['quantity'],
                    'subtotal_ex_vat_cents' => $line['subtotal'],
                    'vat_cents' => $line['total'] - $line['subtotal'],
                    'total_cents' => $line['total'],
                    ...$status,
                    'invoiced_on' => $line['invoicedOn'],
                    'customer_email' => $line['email'],
                    'synced_at' => now(),
                ];

                if (! $record) {
                    $counts['new']++;

                    if (! $dryRun) {
                        Order::create(['external_id' => $externalId, 'import_id' => $import?->getKey(), ...$fields]);
                    }

                    continue;
                }

                $record->fill($fields);
                $changed = $record->isDirty(['status', 'paid_on', 'subtotal_ex_vat_cents', 'total_cents', 'plug_and_pay_product_id']);
                $counts[$changed ? 'updated' : 'unchanged']++;

                if ($changed && ! $dryRun) {
                    $record->save();
                }
            }
        }

        $import?->update(['rows_new' => $counts['new'], 'rows_updated' => $counts['updated'], 'rows_duplicate' => $counts['unchanged']]);
        $paid = $lines->where('status', 'paid');

        return [
            ...($import ? ['import' => $import] : []),
            'total' => $lines->count(),
            'orders' => $lines->pluck('orderId')->unique()->count(),
            ...$counts,
            'skipped' => static::$skipped,
            'paid' => $paid->count(),
            'paid_ex_vat_cents' => (int) $paid->sum('subtotal'),
            'date_from' => $lines->min('invoicedOn'),
            'date_to' => $lines->max('invoicedOn'),
            'unknown_skus' => $lines->pluck('sku')->filter()->unique()->reject(fn (string $sku): bool => isset($products[$sku]))->values()->all(),
        ];
    }

    /**
     * Rows without an invoice number (no final invoice) or order ID in the last file read.
     */
    private static int $skipped = 0;

    /**
     * The file as header-keyed rows, from XLSX or CSV.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(string $path, string $filename): array
    {
        if (str_ends_with(strtolower($filename), '.xlsx')) {
            $reader = new XlsxReader;

            try {
                $reader->open($path);
                $headers = null;
                $rows = [];

                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $values = $row->toArray();

                        if ($headers === null) {
                            $headers = array_map(fn (mixed $value): string => trim((string) $value), $values);

                            continue;
                        }

                        $rows[] = array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), null));
                    }

                    break;
                }
            } catch (Throwable $exception) {
                throw new RuntimeException('Dit Excel-bestand kan niet worden gelezen: '.$exception->getMessage(), previous: $exception);
            } finally {
                $reader->close();
            }
        } else {
            $csv = Csv::read((string) file_get_contents($path));
            $headers = $csv['headers'];
            $rows = $csv['records'];
        }

        $missing = array_diff(self::RequiredColumns, $headers ?? []);

        if ($missing !== []) {
            throw new RuntimeException('Dit lijkt geen Plug & Pay-orderexport: de kolommen '.implode(', ', $missing).' ontbreken.');
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, array{orderId: int, ref: string, sku: string, product: string, quantity: float, subtotal: int, total: int, status: string, paidOn: ?string, invoicedOn: ?string, email: ?string}>
     */
    public static function lines(array $rows): Collection
    {
        static::$skipped = 0;

        return collect($rows)
            ->map(function (array $row): ?array {
                $orderId = trim((string) ($row['order_id'] ?? ''));
                $ref = trim((string) ($row['invoice_number'] ?? ''));

                if (! ctype_digit($orderId) || $ref === '') {
                    static::$skipped++;

                    return null;
                }

                $subtotal = Money::parseToCents(static::scalar($row['amount'] ?? 0) ?: 0);
                $total = filled($row['amount_with_tax'] ?? null) ? Money::parseToCents(static::scalar($row['amount_with_tax'])) : $subtotal;
                $status = strtolower(trim((string) ($row['payment_status'] ?? ''))) ?: 'open';
                // The export gives the payment time in UTC; the API sync counts it on the Dutch date.
                $paidOn = static::date($row['paid_at'] ?? null, fromUtc: true);

                return [
                    'orderId' => (int) $orderId,
                    'ref' => $ref,
                    'sku' => trim((string) static::scalar($row['sku'] ?? '')),
                    'product' => trim((string) ($row['product'] ?? '')),
                    'quantity' => (float) (static::scalar($row['quantity'] ?? 1) ?: 1),
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'status' => $status,
                    'paidOn' => $status === 'paid' ? $paidOn : null,
                    'invoicedOn' => static::date($row['invoice_date'] ?? null),
                    'email' => filled($row['email'] ?? null) ? trim((string) $row['email']) : null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Excel stores numbers as numbers; a whole number like an SKU must not become "888999.0".
     */
    private static function scalar(mixed $value): string|int|float
    {
        return match (true) {
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (int) $value,
            is_int($value), is_float($value) => $value,
            default => trim((string) $value),
        };
    }

    private static function date(mixed $value, bool $fromUtc = false): ?string
    {
        $timezone = $fromUtc ? 'UTC' : 'Europe/Amsterdam';

        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value->format('Y-m-d H:i:s'), $timezone)->timezone('Europe/Amsterdam')->toDateString();
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone)->timezone('Europe/Amsterdam')->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
