<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * "Get the app": one page to send to anyone. It shows the right way to put
 * the app on this phone (one tap on Android, a short guide on iPhone, "open
 * in Chrome/Safari" from WhatsApp or Facebook), and a QR code on a computer.
 */
class InstallController extends Controller
{
    public function show(): View
    {
        return view('install', ['link' => route('install.short')]);
    }
}
