<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramUpdateProcessor $processor): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret', '');

        if ($secret !== '' && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            abort(403);
        }

        $processor->handle($request->all());

        return response()->json([
            'ok' => true,
        ]);
    }
}
