<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PDF;

class FileReportController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'user_id'    => 'nullable|integer|exists:users,id',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        // --- Base Query ---
        $query = FileHistory::with(['user', 'file']);

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [
                $request->start_date,
                $request->end_date
            ]);
        }

        $histories = $query->orderBy('created_at', 'desc')->get();

        // --- Generate PDF ---
        $pdf = PDF::loadView('reports.files', compact('histories'));

        $fileName = 'report_files_' . time() . '.pdf';
        $filePath = public_path('reports/' . $fileName);

        if (!file_exists(public_path('reports'))) {
            mkdir(public_path('reports'), 0777, true);
        }

        $pdf->save($filePath);

        return response()->json([
            'status'  => 'success',
            'message' => 'File history report generated successfully',
            'data'    => [
                'path' => url('reports/' . $fileName),
                'count' => $histories->count(),
            ]
        ]);
    }
}
