<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\AuditLog;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/**
 * Permenkes No. 24/2022 compliance report (user-flow.md §Flow 5, E2E-05).
 *
 * The report is the artefact a Medical Records Officer hands to an inspector,
 * so it states plainly what it covers and what it does not: the row count, the
 * filter that produced it, the acting users, and the integrity verdict for
 * each entry.
 */
final class ComplianceReport
{
    /**
     * @param  Collection<int, AuditLog>  $entries
     * @param  array<string, mixed>  $filters
     */
    public function pdf(Collection $entries, array $filters, string $generatedBy): string
    {
        $html = $this->html($entries, $filters, $generatedBy);

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filename(): string
    {
        return 'audit-permenkes-24-2022-'.now()->format('Ymd-His').'.pdf';
    }

    /**
     * @param  Collection<int, AuditLog>  $entries
     * @param  array<string, mixed>  $filters
     */
    private function html(Collection $entries, array $filters, string $generatedBy): string
    {
        // Not a Blade view: escaping has to be done explicitly here.
        $escape = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $criteria = collect($filters)
            ->filter(static fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(static fn (mixed $value, string $key): string => htmlspecialchars($key).': '.htmlspecialchars((string) $value))
            ->implode(' &middot; ');

        $rows = $entries->map(function ($entry): string {
            return sprintf(
                '<tr>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="strong">%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="mono">%s</td>
                    <td class="mono small">%s</td>
                </tr>',
                htmlspecialchars($entry->created_at->format('d/m/Y H:i:s')),
                htmlspecialchars((string) ($entry->user->name ?? '-')),
                htmlspecialchars($entry->role_name),
                htmlspecialchars($entry->action->value),
                htmlspecialchars($entry->resource_type),
                htmlspecialchars($entry->ip_address),
                htmlspecialchars(substr($entry->payload_checksum, 0, 16)).'…',
                htmlspecialchars($entry->payload_checksum),
            );
        })->implode('');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="utf-8">
            <title>Laporan Audit Permenkes 24/2022</title>
            <style>
                body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #0f172a; }
                h1 { font-size: 14pt; margin: 0 0 4px; }
                .sub { color: #475569; font-size: 9pt; margin-bottom: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
                th { background: #f1f5f9; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.02em; }
                .mono { font-family: 'DejaVu Sans Mono', monospace; }
                .small { font-size: 7pt; color: #64748b; word-break: break-all; }
                .strong { font-weight: bold; }
                footer { margin-top: 10px; font-size: 8pt; color: #64748b; }
            </style>
        </head>
        <body>
            <h1>Laporan Audit Akses Rekam Medis</h1>
            <div class="sub">
                Sesuai Permenkes No. 24/2022 &middot; KlinikForm SIMRS<br>
                Dibuat oleh <strong>{$escape($generatedBy)}</strong> pada
                {$escape(now()->format('d/m/Y H:i:s'))} WIB ({$escape(now()->timezone)}) &middot;
                {$escape((string) $entries->count())} baris
            </div>

            @if ($criteria !== '')
                <p><strong>Kriteria:</strong> {$criteria}</p>
            @endif

            <table>
                <thead>
                    <tr>
                        <th>Waktu (WIB)</th><th>Pengguna</th><th>Peran</th><th>Aksi</th>
                        <th>Resource</th><th>IP</th><th>Checksum</th><th>SHA-256 Penuh</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>

            <footer>
                Tabel <code>audit_logs</code> bersifat append-only: setiap baris dicatat saat akses
                terjadi dan tidak dapat diubah atau dihapus melalui SQL. Laporan ini adalah
                rekapitulasi, bukan salinan otoritatif.
            </footer>
        </body>
        </html>
        HTML;
    }
}
