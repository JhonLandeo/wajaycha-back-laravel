<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Symfony\Component\HttpFoundation\JsonResponse;

class TagsController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Tag::where('user_id', auth()->id())->get(['id', 'name']);

        return response()->json($data);
    }
}
