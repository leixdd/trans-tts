<?php

namespace App\Http\Controllers;

use App\Services\AnonymousVisitor;
use App\Services\TranslationTurnStreamService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TranslationTurnStreamController extends Controller
{
    /**
     * Relay persisted Novita progress as an application SSE feed for the owning visitor.
     */
    public function __invoke(
        Request $request,
        string $workflow,
        TranslationTurnStreamService $streams,
        AnonymousVisitor $visitors,
    ): StreamedResponse {
        // Release the session lock before the long-lived stream holds the worker.
        $request->session()->save();

        return $streams->stream($workflow, $visitors->idFrom($request));
    }
}
