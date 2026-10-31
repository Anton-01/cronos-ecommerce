<?php

declare(strict_types=1);

namespace Cronos\Reports\Interfaces\Http;

use Cronos\Reports\Application\ExportService;
use Cronos\Reports\Domain\Exceptions\ReportError;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The only way out for an export file: a signed, expiring link. Holding the link is the credential, so it expires and downloads are counted. */
final class ExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $export, ExportService $exports): StreamedResponse
    {
        try {
            $job = $exports->openForDownload($export);
        } catch (ReportError) {
            abort(410, 'That export is no longer available. Request it again from the panel.');
        }

        $name = sprintf('%s-%s.xlsx', $job['type'], substr($job['created_at'], 0, 10));

        return Storage::disk('local')->download($job['file_path'], $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
    }
}
