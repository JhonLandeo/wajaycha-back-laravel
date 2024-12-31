<?php

namespace App\Http\Controllers;

use App\Services\OpenAiService;
use Illuminate\Http\Request;

class ChatGptController extends Controller
{
    protected $openAIService;

    public function __construct(OpenAiService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    public function chat(Request $request)
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => $request->input('message')],
        ];

        $response = $this->openAIService->chat($messages);

        return response()->json($response);
    }
}
