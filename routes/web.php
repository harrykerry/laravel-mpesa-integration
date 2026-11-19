<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The landing page is a Livewire component: it documents the API, shows what
| this deployment has configured, and runs a sandbox STK push through the
| very same service the API uses.
|
*/

Route::livewire('/', 'welcome');
