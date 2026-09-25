<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::get('/einladung/{token}/akzeptieren', [InvitationController::class, 'accept'])
    ->name('invitations.accept');

Route::get('/dokumente/{attachment}', [AttachmentController::class, 'download'])
    ->middleware('auth')
    ->name('attachments.download');
