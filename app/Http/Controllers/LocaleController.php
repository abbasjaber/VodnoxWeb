<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function switch(Request $request, string $lang)
    {
        if (in_array($lang, ['ar', 'en'])) {
            $request->session()->put('locale', $lang);
        }

        return back();
    }
}
