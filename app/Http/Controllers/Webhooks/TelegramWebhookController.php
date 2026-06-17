<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Receives Telegram bot updates. The webhook URL embeds a shared secret and
 * Telegram also echoes it via the X-Telegram-Bot-Api-Secret-Token header.
 *
 * Connection flow: the user sends "/start <CODE>" (or just the code) to the bot;
 * we look up the unexpired code, store the chat_id, and confirm in-chat.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramConnectionService $connections,
        private readonly TelegramBotService $telegram,
    ) {}

    public function __invoke(Request $request, string $secret): JsonResponse
    {
        // Validate both the path secret and the header Telegram sends back.
        $expected = config('tradenews.telegram.webhook_secret');
        $headerSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_unless(
            hash_equals((string) $expected, (string) $secret)
            && ($headerSecret === null || hash_equals((string) $expected, (string) $headerSecret)),
            403,
        );

        $message = $request->input('message', []);
        $chatId = (string) data_get($message, 'chat.id', '');
        $text = trim((string) data_get($message, 'text', ''));
        $username = data_get($message, 'from.username');

        if ($chatId === '' || $text === '') {
            return response()->json(['ok' => true]);
        }

        $code = $this->extractCode($text);

        if ($code === null) {
            $this->telegram->sendMessage(
                $chatId,
                "👋 Welcome to TradeNews alerts.\nPaste the connection code from your <b>Settings → Telegram</b> page to link your account.",
            );

            return response()->json(['ok' => true]);
        }

        $integration = $this->connections->linkChat($code, $chatId, $username);

        $this->telegram->sendMessage(
            $chatId,
            $integration !== null
                ? '✅ Your Telegram is now linked to TradeNews. You will receive your selected alerts here.'
                : '⚠️ That code is invalid or expired. Generate a new one from Settings → Telegram.',
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Accept "/start CODE", "/start", or a bare 8-char code.
     */
    private function extractCode(string $text): ?string
    {
        $text = Str::of($text)->replaceFirst('/start', '')->trim()->upper()->toString();

        return preg_match('/^[A-Z0-9]{6,16}$/', $text) ? $text : null;
    }
}
