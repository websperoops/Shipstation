<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', 'HomeController@index');
Route::get('/check', 'HomeController@check');

Route::get('/update-iconic-stock', 'HomeController@cronToUpdateIconicStock');


Route::post('/webhook', 'HomeController@webhookResonse');
Route::post('/dear-webhook', 'HomeController@dearWebhookResonse');
Route::post('/update-stock', 'HomeController@updateIconicStock');

Route::get('upload-order', 'HomeController@addOrderIntoShipStation');
Route::get('order/create', 'HomeController@createOrder');
Route::get('webhook-response', 'HomeController@getWebhookResonse');
Route::get('get-product', 'HomeController@getProductsFromIconic');
Route::get('update-status', 'HomeController@updateIconicOrderStatus');
Route::get('products', 'HomeController@getProductsFromDear');
Route::get('delete-order', 'HomeController@deleteOrder');
Route::get('dear', 'HomeController@dearWebhook');
Route::get('/webhooks', 'HomeController@dearResonse');
Route::get('/create-webhook', 'HomeController@createDearWebhook');
Route::get('/create-location', 'HomeController@createLocation');
Route::get('/update-tracking', 'HomeController@updateTracking');
Route::get('/dear-cron', 'HomeController@cronjobForDearInvoices');

Route::get('/test/dear/invoice', 'HomeController@createDearInvoices');



Route::get('ship-cron-test', 'HomeController@testShipOrderUpload');
Route::get('ship-cron-test-order', 'HomeController@testOrder');



