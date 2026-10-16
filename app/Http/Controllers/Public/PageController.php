<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SettingsService;

class PageController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function services()
    {
        return view('public.services');
    }

    public function pricing()
    {
        // Data-driven pricing: real plans only. When nothing is published the
        // view falls back to a "contact us" state instead of inventing numbers.
        $plans = Plan::query()
            ->where('is_active', true)
            ->with('limits')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return view('public.pricing', [
            'plans' => $plans,
            'pricingNote' => $this->settings->get('pricing_note'),
        ]);
    }

    public function about()
    {
        return view('public.about');
    }

    public function contact()
    {
        return view('public.contact');
    }
}
