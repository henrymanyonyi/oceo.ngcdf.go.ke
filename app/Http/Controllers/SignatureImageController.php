<?php

namespace App\Http\Controllers;

use App\Models\UserSignature;
use App\Services\AccessLogger;
use App\Services\SignatureRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A signature image, shown only to its owner (Profile) and to a verifier (Signatures). Never cached,
 * never offered as a download, and every view is in the access log. On memos the image is embedded in
 * the PDF from a signing record, not fetched from here.
 */
class SignatureImageController extends Controller
{
    public function __invoke(Request $request, UserSignature $signature, SignatureRegistry $registry, AccessLogger $log): Response
    {
        abort_unless($registry->mayView($request->user(), $signature), 403);

        $log->log(AccessLogger::VIEW, $signature, ['what' => 'signature image', 'owner' => $signature->user_id]);

        return response($registry->contents($signature), 200, [
            'Content-Type' => $signature->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
