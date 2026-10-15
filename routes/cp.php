<?php

use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\SignOff\Http\Controllers\CP\ReviewsController;

Route::get('sign-off', [ReviewsController::class, 'index'])->name('sign-off.index');
Route::get('sign-off/compare/{entry}', [ReviewsController::class, 'compare'])->name('sign-off.compare');
