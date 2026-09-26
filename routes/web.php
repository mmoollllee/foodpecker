<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PackingListController;
use Illuminate\Support\Facades\Route;

Route::get('/einladung/{token}/akzeptieren', [InvitationController::class, 'accept'])
    ->name('invitations.accept');

Route::get('/dokumente/{attachment}', [AttachmentController::class, 'download'])
    ->middleware('auth')
    ->name('attachments.download');

Route::get('/runden/{round}/packliste', [PackingListController::class, 'show'])
    ->middleware('auth')
    ->name('rounds.packing-list');
