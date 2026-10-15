<?php

namespace App\Http\Controllers;

use App\Services\AffiliateContracts;
use App\Services\ESignatures;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ESignaturesWebhookController extends Controller
{
    /**
     * Receive contract status updates from eSignatures.com.
     */
    public function __invoke(Request $request, ESignatures $eSignatures, AffiliateContracts $contracts): Response
    {
        abort_unless($eSignatures->hasValidSignature($request->getContent(), $request->header('X-Signature-SHA256')), 403);

        $contracts->handleWebhook($request->json()->all());

        return response()->noContent();
    }
}
