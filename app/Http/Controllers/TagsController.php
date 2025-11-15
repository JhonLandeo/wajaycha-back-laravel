<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class TagsController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Tag::all();
        return response()->json($data);
    }
}
