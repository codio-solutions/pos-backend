<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\InventoryReport;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function salesAndStock(Request $request)
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(Carbon::parse($data['from'])->diffInDays($data['to']) > 366, 422, 'Choose a report period of at most one year.');

        return response()->json((new InventoryReport)->generate($data['from'], $data['to']));
    }
}
