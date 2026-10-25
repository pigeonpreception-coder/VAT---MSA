<?php

use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public marketing site
|--------------------------------------------------------------------------
|
| The pages every visitor lands on before signing in or applying for access
| (App\Http\Controllers\Site\SiteController). Required from routes/web.php,
| and deliberately outside every auth/guest group there: these are plain,
| unauthenticated reads.
|
| Paths avoid the authenticated app's own top-level URLs -- /security,
| /platform, /developer, /documents, /reports and /licensing are all taken
| by console screens -- which is why the public "security" page lives at
| /trust and the product overview at /product.
|
| `/` itself still sends an already-signed-in user straight to their
| dashboard (see SiteController::home()), so existing bookmarks keep working.
|
*/

Route::get('/', [SiteController::class, 'home'])->name('site.home');
Route::get('/product', [SiteController::class, 'product'])->name('site.product');
Route::get('/trust', [SiteController::class, 'trust'])->name('site.trust');
Route::get('/plans', [SiteController::class, 'plans'])->name('site.plans');

// Bare /verify is the lookup form; /verify/{token} (the certificate result
// page itself) is registered in routes/web.php and is unaffected.
Route::get('/verify', [SiteController::class, 'verify'])->name('site.verify');

Route::get('/sitemap.xml', [SiteController::class, 'sitemap'])->name('site.sitemap');
