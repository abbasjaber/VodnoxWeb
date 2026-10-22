<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\GatewayDevice;
use App\Services\AuditService;
use App\Services\Telephony\GatewayProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;
use Throwable;

/**
 * The printable installation guide of one GSM gateway device.
 *
 * Available inside a live technical session only. It is built from the device's
 * real data — the same values the workspace shows — and never invents a
 * destination: the inbound path number is the per-port number the server
 * allocated, while the *destination* of an incoming call stays owned by the
 * numbers & routing workspace.
 *
 * The document itself is English only and left to right, whatever language the
 * dashboard is in: the engineer reads it at the device, where the page and field
 * names are English. The on-screen workspace stays bilingual.
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

        $profiles = $this->gateways->profiles();

        /*
         * The export is the one place that depends on a runtime the CI job
         * cannot prove: the mPDF package must actually exist in the deployed
         * `vendor/` tree, its Arabic font must be readable, and `storage/app`
         * must be writable for its scratch files. Any failure here is therefore
         * reported with the real exception, its file and line, and a reference
         * the operator can grep — never swallowed and never answered with a
         * false success. The response stays a plain error, so no half-written
         * "PDF" and no 500 page is handed to the browser.
         */
        try {
            // Build the export from the real routing rows. `defaultDestination`
            // already resolves to Extension; there is no nested `destination`
            // relation on Extension, and trying to eager-load one crashes the
            // export before the failure can be logged.
            $gateway->load('lines.route.defaultDestination');

            $html = view('company.gateway-setup-guide', [
                'org' => $organization,
                'gateway' => $gateway,
                'serverSettings' => $this->gateways->serverSettings(),
                'profile' => $profiles[$gateway->model] ?? null,
                'includePasswords' => $includePasswords,
                'exportedAt' => now(),
            ])->render();

            $pdf = $this->renderPdf($html);
        } catch (Throwable $exception) {
            $reference = 'SG-'.strtoupper(Str::random(8));

            Log::error('gateway.setup_guide_failed '.$reference, [
                'reference' => $reference,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                // File/line/function of each frame WITHOUT its arguments: the
                // arguments of this call chain carry the rendered HTML, and an
                // export that includes SIP passwords must never put them in a
                // log file.
                'trace' => collect($exception->getTrace())->take(25)->map(fn (array $frame): string => sprintf(
                    '%s%s%s() at %s:%s',
                    $frame['class'] ?? '',
                    $frame['type'] ?? '',
                    $frame['function'] ?? '',
                    $frame['file'] ?? '-',
                    $frame['line'] ?? '-',
                ))->all(),
                'gateway_id' => $gateway->getKey(),
                'organization_id' => $organization->getKey(),
                'include_passwords' => $includePasswords,
                'php_version' => PHP_VERSION,
                'mpdf_available' => class_exists(Mpdf::class),
                'mpdf_path' => base_path('vendor/mpdf/mpdf/src/Mpdf.php'),
                'mpdf_path_exists' => File::exists(base_path('vendor/mpdf/mpdf/src/Mpdf.php')),
                'temp_dir' => $this->tempDir(),
                'temp_dir_writable' => File::isWritable($this->tempDir()),
            ]);

            return redirect()->route('company.gateways.index')
                ->with('error', __('company.gateway_guide_failed', ['reference' => $reference]));
        }

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
     * Render the guide as A4, English and left to right.
     *
     * The document is deliberately not localised: it is read at the device, so
     * every heading, step and table in it is English and the direction is LTR
     * whatever language the dashboard was in when the export was started. The
     * values are printed exactly as they are stored.
     */
    private function renderPdf(string $html): string
    {
        // mPDF needs a writable scratch directory for its own temporary files;
        // it lives in the private storage tree, never in public/. mPDF removes
        // its temporary files itself after Output().
        $tempDir = $this->tempDir();
        File::ensureDirectoryExists($tempDir);

        // A deployed `vendor/` that never received the package fails here with
        // the class name only. Naming the missing dependency and the exact
        // command keeps the operator's log actionable.
        if (! class_exists(Mpdf::class)) {
            throw new RuntimeException(
                'mPDF is not autoloadable. Run "composer install --no-dev --optimize-autoloader" '
                .'in the application root so vendor/mpdf/mpdf reaches the server.'
            );
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'default_font' => 'xbriyaz',
            'tempDir' => $tempDir,
            'directionality' => 'ltr',
            'margin_left' => 13,
            'margin_right' => 13,
            'margin_top' => 15,
            'margin_bottom' => 18,
            'margin_footer' => 7,
            // The guide is English only, so nothing in it has to be script- or
            // language-detected at render time.
            'autoScriptToLang' => false,
            'autoArabic' => false,
        ]);

        $mpdf->SetDirectionality('ltr');
        $mpdf->SetTitle('VODNOX — GSM Gateway Setup Guide');
        $mpdf->SetAuthor('VODNOX');
        $mpdf->SetSubject('GSM gateway installation guide');

        // A page footer in the same language as the rest of the document. The
        // running head is left out on purpose: the brand block at the top of the
        // first page already carries the identity, and repeating it on every
        // page costs vertical space the step tables need.
        $mpdf->SetHTMLFooter(
            '<div style="border-top: 0.6pt solid #c2cddd; padding-top: 3pt; '
            .'font-size: 7.5pt; color: #46566f; text-align: center;">'
            .'VODNOX · GSM Gateway Setup Guide · Page {PAGENO} of {nbpg}'
            .'</div>'
        );

        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** The private scratch directory mPDF is allowed to write into. */
    private function tempDir(): string
    {
        return storage_path('app/mpdf');
    }
}
