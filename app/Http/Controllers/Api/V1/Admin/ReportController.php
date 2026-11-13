<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    public function index(Request $request, ReportService $reports): JsonResponse
    {
        return $this->success('Report retrieved.', $reports->build($request->user()));
    }
}
