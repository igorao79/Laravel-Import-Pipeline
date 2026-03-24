<?php

use App\Http\Controllers\Web\ImportController;
use Illuminate\Support\Facades\Route;

// Auth
Route::get('/login', fn () => view('auth.login'))->name('login');

Route::post('/login', function (Illuminate\Http\Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    if (auth()->attempt($credentials)) {
        $request->session()->regenerate();
        return redirect()->intended('/imports');
    }

    return back()->withErrors(['email' => 'Неверный email или пароль.']);
});

Route::post('/logout', function (Illuminate\Http\Request $request) {
    auth()->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/login');
})->name('logout');

// Imports (protected)
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect('/imports'));
    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('/imports/{import}/retry', [ImportController::class, 'retry'])->name('imports.retry');
});
