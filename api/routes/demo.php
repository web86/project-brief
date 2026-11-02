<?php

use App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/api/demo/status', [DemoController::class, 'status']);
Route::get('/api/demo/enter/{role}', [DemoController::class, 'enter'])->whereIn('role', ['developer', 'client']);
