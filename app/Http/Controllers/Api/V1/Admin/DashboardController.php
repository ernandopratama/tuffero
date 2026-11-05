<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function index(Request $request, DashboardService $dashboard): JsonResponse
    {
        $data = $dashboard->build($request->user());

        // Akun tanpa role bukan staf; widget lain sudah memeriksa izinnya masing-masing.
        abort_if($data === null, 403);

        return $this->success('Dashboard retrieved.', $data);
    }
}
