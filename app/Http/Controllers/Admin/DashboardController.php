<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivationRequest;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $stats = [
            'organizations' => Organization::count(),
            'active_organizations' => Organization::whereIn('status', ['active', 'trial'])->count(),
            'users' => User::whereNotNull('organization_id')->count(),
            'revenue' => (float) Invoice::where('status', 'paid')->sum('amount'),
            'pending_requests' => ActivationRequest::where('status', 'pending')->count(),
            'unpaid_invoices' => Invoice::whereIn('status', ['unpaid', 'overdue'])->count(),
            'active_subscriptions' => Subscription::where('status', 'active')->count(),
        ];

        $recentOrganizations = Organization::latest()->limit(5)->get();
        $recentActivity = AuditLog::with('user')->latest()->limit(6)->get();

        return view('admin.index', compact('stats', 'recentOrganizations', 'recentActivity'));
    }
}
