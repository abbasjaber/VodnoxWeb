<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\GatewayDevice;
use App\Services\AuditService;
use App\Services\Telephony\GatewayProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * The printable wiring guide of one GSM gateway device.
 *
 * Available inside a live technical session only. It is built from the device's
 * real data — the same values the workspace shows — and never invents a
 * destination: the inbound path number is the per-port number the server
 * allocated, while the *destination* of an incoming call stays owned by the
 * numbers & routing workspace.
 *
 * SIP passwords are excluded by default and are only included when the caller
 * explicitly asks for them AND holds the technical permission to reveal
 * secrets. Exporting never creates or rotates a password.
 */
class GatewaySetupGuideController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private readonly AuditService $audit,
        private readonly GatewayProvisioningService $gateways,
    ) {}

    public function download(Request $request, GatewayDevice $gateway)
    {
        $this->authorizePermission($request, 'view_gateways');
        $organization = $this->organization($request);
        $this->authorizeTenant($organization, $gateway);

        $includePasswords = $request->boolean('include_passwords');

        if ($includePasswords) {
            // The permission to reveal secrets is required IN ADDITION to the
            // technical session — it never substitutes for it.
            $this->authorizePermission($request, 'view_gateway_secrets');
        }

        $gateway->load('lines.route.defaultDestination.destination');

        $profiles = $this->gateways->profiles();

        $html = view('company.gateway-setup-guide', [
            'org' => $organization,
            'gateway' => $gateway,
            'serverSettings' => $this->gateways->serverSettings(),
            'profile' => $profiles[$gateway->model] ?? null,
            'includePasswords' => $includePasswords,
            'exportedAt' => now(),
        ])->render();

        $pdf = $this->renderPdf($html);

        // The filename carries only the device id and the export date.
        $filename = sprintf('vodnox-setup-guide-%d-%s.pdf', $gateway->getKey(), now()->format('Ymd'));

        $this->audit->log(
            'gateway.setup_guide_downloaded',
            $gateway,
            [],
            [
                'include_passwords' => $includePasswords,
                'ports' => $gateway->lines->count(),
            ],
            $request->user(),
            $organization->getKey(),
            auth('technical')->id(),
        );

        // Streamed straight from memory: never written into public/, never into
        // a public storage disk, never given a public URL, and never cached.
        return response()->streamDownload(function () use ($pdf) {
            echo $pdf;
        }, $filename, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Render the guide as A4, bilingual (Arabic + English) and RTL, with an
     * embedded Arabic font so the text stays selectable and copyable.
     */
    private function renderPdf(string $html): string
    {
        // mPDF needs a writable scratch directory for its own temporary files;
        // it lives in the private storage tree, never in public/. mPDF removes
        // its temporary files itself after Output().
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'xbriyaz',
            'tempDir' => $tempDir,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 14,
            'margin_bottom' => 16,
            'autoScriptToLang' => true,
            'autoArabic' => true,
        ]);

        $mpdf->autoScriptToLang = true;
        $mpdf->autoArabic = true;
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle('VODNOX - Telephony Technical Settings - Setup guide');

        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }
}
