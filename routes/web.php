<?php

use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/einladung/{token}/akzeptieren', [InvitationController::class, 'accept'])
    ->name('invitations.accept');
