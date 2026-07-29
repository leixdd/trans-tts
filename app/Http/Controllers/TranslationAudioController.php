<?php

namespace App\Http\Controllers;

use App\Services\AnonymousVisitor;
use App\Services\TranslationWorkflowStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TranslationAudioController extends Controller
{
    /**
     * Stream private WAV audio when the signed URL is valid and the visitor owns the turn.
     */
    public function __invoke(
        Request $request,
        string $workflow,
        TranslationWorkflowStore $store,
        AnonymousVisitor $visitors,
    ): StreamedResponse {
        $visitorId = $visitors->idFrom($request);
        $record = $store->requireOwned($workflow, $visitorId);

        if ($record['status'] !== 'completed' || blank($record['audio_path'])) {
            abort(404);
        }

        $path = $record['audio_path'];
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return response()->stream(function () use ($disk, $path): void {
            $stream = $disk->readStream($path);

            if ($stream === null) {
                return;
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'audio/wav',
            'Content-Disposition' => 'inline; filename="translation.wav"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
