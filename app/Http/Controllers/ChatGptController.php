<?php

namespace App\Http\Controllers;

use App\Services\OpenAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Js;

class ChatGptController extends Controller
{
    protected OpenAiService $openAIService;

    public function __construct(OpenAiService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    public function chat(Request $request): JsonResponse
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => $request->input('message')],
        ];

        $response = $this->openAIService->chat($messages);

        return response()->json($response);
    }
}
