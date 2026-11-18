<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\ReportService;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function show(Request $request, string $type): JsonResponse
    {
        [$from, $to, $label] = $this->range($request);
        $report = $this->reports->build($type, $from, $to);

        return $this->ok(array_merge($report, [
            'type' => $type,
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label],
            'rows' => array_slice($report['rows'], 0, 500),
            'truncated' => count($report['rows']) > 500,
        ]));
    }

    public function export(Request $request, string $type, AuditLogger $audit, SettingsService $settings): Response
    {
        $request->validate(['format' => ['required', 'in:csv,xlsx,pdf']]);
        [$from, $to, $label] = $this->range($request);
        $report = $this->reports->build($type, $from, $to);
        $filename = "stayvoyage-{$type}-{$from->toDateString()}-to-{$to->toDateString()}";
        $audit->log('report.exported', null, null, ['type' => $type, 'format' => $request->query('format'), 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return match ($request->query('format')) {
            'csv' => $this->csv($report, $filename),
            'xlsx' => $this->xlsx($report, $filename),
            'pdf' => Pdf::loadView('pdf.report', [
                'title' => ucfirst($type).' report', 'label' => $label, 'from' => $from, 'to' => $to, 'report' => $report, 'site' => $settings->all(),
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf'),
        };
    }

    private function csv(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, $report['columns']);
            foreach ($report['rows'] as $row) {
                // Neutralise spreadsheet formula injection.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report) {
            $writer = new XlsxWriter();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($report['columns']));
            foreach ($report['rows'] as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row)));
            }
            $writer->close();
        }, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function range(Request $request): array
    {
        $request->validate([
            'range' => ['nullable', 'in:today,yesterday,last_7_days,last_30_days,this_month,last_month,custom'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:range,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:range,custom'],
        ]);

        return $this->reports->range($request->query('range'), $request->query('from'), $request->query('to'));
    }
}
