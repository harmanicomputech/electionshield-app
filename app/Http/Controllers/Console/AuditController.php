<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(): View
    {
        return view('audit.index', ['logs' => AuditLog::query()->latest('created_at')->latest('id')->simplePaginate(50)]);
    }
}
