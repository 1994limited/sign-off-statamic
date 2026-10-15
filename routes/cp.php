<?php

use Illuminate\Support\Facades\Route;
use NineteenNinetyFour\SignOff\Http\Controllers\CP\ReviewsController;

Route::get('sign-off', [ReviewsController::class, 'index'])->name('sign-off.index');
Route::get('sign-off/compare/{entry}', [ReviewsController::class, 'compare'])->name('sign-off.compare');
Route::get('sign-off/compare/{entry}/preview/{file}/{size?}', [ReviewsController::class, 'preview'])->name('sign-off.preview')->where('size', 'small');
