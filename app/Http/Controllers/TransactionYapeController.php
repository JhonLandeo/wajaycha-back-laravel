<?php

namespace App\Http\Controllers;

use App\Imports\TransactionYapeImport;
use App\Models\TransactionYape;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class TransactionYapeController extends Controller
{
    public function import(Request $request)
    {
        try {
            $file = $request->file('file');
            Excel::import(new TransactionYapeImport(), $file);
            return response()->json(['status' => 'ok']);
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}
