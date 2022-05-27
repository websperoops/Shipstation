<?php

namespace App\Http\Controllers;

use DB;

use Response;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use RocketLabs\SellerCenterSdk\Core\Client;
use RocketLabs\SellerCenterSdk\Core\Configuration;
use RocketLabs\SellerCenterSdk\Endpoint\Endpoints;
use RocketLabs\SellerCenterSdk\Core\Request\GenericRequest;
use RocketLabs\SellerCenterSdk\Core\Response\ErrorResponse;
use RocketLabs\SellerCenterSdk\Core\Response\SuccessResponseInterface;

use LaravelShipStation\ShipStation;
use LaravelShipStation\Models\Address;
use LaravelShipStation\Models\OrderItem;
use LaravelShipStation\Models\Order;
use LaravelShipStation\Models\Webhook;
use UmiMood\Dear\Dear;
use UmiMood\Dear\Api\AttributeSet;

use App\WebhooksResponse;
use App\DailyIconicOrders;
use App\ShipstationOrders;

use DateTime;

class HomeController extends Controller
{
    public function index(Request $request){

        echo "Inventory Management home controller";
        
    }

    public function check(){

        echo "This is check function";
        
    }
   
    public function cronToUpdateIconicStock(Request $request) {
     echo "test cron";die;
        echo $msg = 'Iconic Stock update Start ';
        Log::channel('iconic_stockupdate')->info($msg);

        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));


        // $data = array ('Location' => 'The Iconic');
        // $products = $dear->ProductAvailability()->get($data);
        $products = $dear->ProductAvailability()->get([]);
        $totalPage =  $products['Total'];
        
        foreach ($products['ProductAvailabilityList'] as $key => $product) {
            $this->updateStock($product);
        }
       
        if ($totalPage > 100) {
            $lastPage = $totalPage/100;
            if (strpos($lastPage, '.') !== false) {
                $pageCountArray = (explode(".",$lastPage));
                if ($pageCountArray[1] !== '00') {
                    $lastPage = $pageCountArray[0] + 1;
                }
            }
            for ($x = 2; $x <= $lastPage; $x++) {
                $products = $dear->ProductAvailability()->get(['Page'=> $x]);
                foreach ($products['ProductAvailabilityList'] as $key => $product) {
                    $this->updateStock($product);
                }
               
            }
        }

        $msg = 'Iconic Stock update Stop';
        Log::channel('iconic_stockupdate')->info($msg);
        die;
       
      
       
    }
    public function updateStock ($product) {
        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));

        try {
            $iconicProduct = Endpoints::product()->getProducts()->setSearch($product['SKU'])->build()->call($client);
           
            if (count($iconicProduct->getBody()['Products']) > 0 && isset($iconicProduct->getBody()['Products']['Product'])) {

                $existingProduct = $iconicProduct->getBody()['Products']['Product'];
               
                if ($product['Available'] == '-1') {
                    $product['Available'] = 0;
                }
                
                if ($existingProduct['Available'] !== $product['Available'] ) {
                    try {
                        echo $msg = 'Iconic Stock update for product '.$product['SKU'].' available Quantity will be '.$product['Available'];
                        Log::channel('iconic_stockupdate')->info($msg);
            
                        echo $msg = 'Previous Quantity was '.$existingProduct['Available'];
                        Log::channel('iconic_stockupdate')->info($msg);

                        $response = $client->call(
                            (new GenericRequest(
                                Client::POST,
                                'ProductStockUpdate',
                                GenericRequest::V1,
                                [],
                                [
                                    'Product' => [
                                        'SellerSku' => $product['SKU'],
                                        'Quantity' => $product['Available'],
                                    ]
                                ]
                            ))
                        );

                    if ($response instanceof SuccessResponseInterface) {
                        printf("Feed has been created. Feed id = %s\n", $response->getHead()['RequestId']);
                    } else {
                        /** @var $response ErrorResponse */
                        printf("Error %s\n", $response->getMessage());
                    }

                    }
                    catch(\Exception $e) {
                        $msg = 'Something wents wrong with '.$product['SKU'];
                        Log::channel('iconic_stockupdate')->info($msg);   
                    } 
                    
                }

            } 

       
        } catch(\Exception $e) {
            $msg = 'Product is not available with '.$product['SKU'];
            Log::channel('iconic_stockupdate')->info($msg);   
        } 
    }


    public function updateIconicStock(Request $request){

        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        $data = $request->all();
        Log::channel('iconic_stockupdate')->info(json_encode($data));   
        foreach ($data as $key => $value) {
                if(isset($value['Location']) && $value['Location'] == 'The Iconic'){
                $productData = $value;
                $sku = $productData['SKU'];
                $availableQuantity = $productData['Available'];
                Log::channel('iconic_stockupdate')->info('SKU:'.$sku.'Quantity:'.$availableQuantity);
                $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
                $productCollectionRequest = Endpoints::product()->productUpdate();
                $productCollectionRequest->updateProduct($sku)->setQuantity($availableQuantity);
                $response = $productCollectionRequest->build()->call($client);
                Log::channel('iconic_stockupdate')->info(json_encode($response));
            }   
        }
    }

    public function cronjobForDearInvoices()
    {   
        $yesterday = date("Y-m-d", strtotime( '-1 days' ) );
        Log::channel('dear_saleinvoice')->info('Create sale into dear Start');

        $allOrders = DailyIconicOrders::whereDate('created_at', $yesterday )->where('is_invoiced','!=','1')->get()->toArray();
        if (!empty($allOrders)) {
            $client  = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
            $orderId = '';
            $dear    = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));

            $norwoodLocation = $dear->Location()->get(['Name' => 'Norwood Warehouse']);

            if (!empty($norwoodLocation)) {
                $locationList = $norwoodLocation['LocationList'];
                $locationId   = $locationList[0]['ID']; 
                $locationName = $locationList[0]['Name'];
            }
            $customerResponse = $dear->Customer()->get(['Name' => 'The Iconic']);
            $customer         = $customerResponse['CustomerList'][0];
           
            $parameters = [
                            "CustomerID"          => $customer['ID'],
                            "Location"            => $customer['Location'],
                            "CurrencyRate"        => "1",
                            "Terms"               => $customer['PaymentTerm'], 
                            "PriceTier"           => $customer['PriceTier'] ? $customer['PriceTier'] : 'Retail - Standard',
                            "SalesRepresentative" => $customer['SalesRepresentative'] ? $customer['SalesRepresentative'] : 'Charlie Hender',
                            "Location"            => $locationName,
                            "TaxRule"             => $customer['TaxRule'] ? $customer['TaxRule'] : 'GST on Income',
                            "TaxInclusive"        => true
                        ];
                       
            $saleResponse = $dear->sale()->create($parameters);
            $saleId       = $saleResponse['ID'];
            Log::channel('dear_saleinvoice')->info('saleResponse');
            Log::channel('dear_saleinvoice')->info('saleId'.$saleId);
           
            foreach ($allOrders as $order) {
                $orderId = $order['order_id'];
                if ($orderId != '') {
                    $orderData = Endpoints::order()->getOrder($orderId)->call($client);
                    if (isset($orderData->getBody()['Orders']['Order']) && !empty($orderData->getBody()['Orders']['Order'])) {
                        $itemData = Endpoints::order()->getOrderItems($orderId)->call($client);
                        
                        if (isset($itemData->getBody()['OrderItems']['OrderItem']) && !empty($itemData->getBody()['OrderItems']['OrderItem'])) {
                            $orderItems = $itemData->getBody()['OrderItems']['OrderItem'];
                            
                            if (isset($orderItems['OrderItemId'])) {  
                                $orderItems = $itemData->getBody()['OrderItems'];
                            } else {
                                $orderItems = $itemData->getBody()['OrderItems']['OrderItem'];
                            }
                            
                            foreach ($orderItems as $item) {
                                if ($sku = '') {
                                    $sku = $item['Sku'];
                                } else {
                                    if ($sku == $item['Sku']) {
                                        $arr[$sku][] = [ 
                                                        "SKU"     => $item['Sku'],
                                                        "Name"    => $item['Name'],
                                                        "Price"   => $item['PaidPrice'],
                                                        "Tax"     => $item['TaxAmount'],
                                                        "TaxRule" => "GST on Income",
                                                        "Total"   => $item['PaidPrice']
                                                    ];
                                    } else {
                                        $sku = $item['Sku'];
                                        $arr[$sku][] =  [ 
                                                        "SKU"     => $item['Sku'],
                                                        "Name"    => $item['Name'],
                                                        "Price"   => $item['PaidPrice'],
                                                        "Tax"     => $item['TaxAmount'],
                                                        "TaxRule" => "GST on Income",
                                                        "Total"   => $item['PaidPrice']
                                                    ];
                                    }
                                }
                            }
                        }
                    }
                }
                $update = DailyIconicOrders::where('id', $order['id'])->update(['is_invoiced' => '1']);
            }
            $i = 1;
            foreach ($arr as $key => $value) {
                $qty   = count($value);
                $total = $qty*$value[0]['Price'];

                $orderLine[] =  array(
                                    "SKU"      => $value[0]['SKU'],
                                    "Name"     => $value[0]['Name'],
                                    "Quantity" => $qty,
                                    "Price"    => $value[0]['Price'],
                                    "Tax"      => $value[0]['Tax'],
                                    "TaxRule"  => "GST on Income",
                                    "Total"    => $total,
                                );
                $box = 'Box'.$i;
                
                $pickPackLine[] =  array(
                                            "SKU"        => $value[0]['SKU'],
                                            "Name"       => $value[0]['Name'],
                                            "Location"   => $locationName,
                                            "LocationID" => $locationId,
                                            "Quantity"   => $qty,
                                            "Box"        => $box,  
                                        );
                $shipLine[] =  array(
                                            "Box"     => $box,
                                            "Carrier" => "AustraliaPost",
                                        );
                $i++;
            }
            $parameters = [
                            "SaleID" => $saleId,
                            "Status" => "AUTHORISED",
                            "Lines"  => $orderLine,
                        ];
                
            $pickPackParam =  [
                                "TaskID"               => $saleId,
                                "Status"               => "AUTHORISED",
                                "AutoPickPackShipMode" => "AUTOPICKPACKSHIP",
                                "Lines"                => $pickPackLine,
                            ];
            $shipParam =  [
                            "TaskID" => $saleId,
                            "Status" => "AUTHORISED",
                            "Lines"  => $shipLine,
                        ];
            try {
                $response = $dear->SaleOrder()->create($parameters);
                Log::channel('dear_saleinvoice')->info('saleOrderResponse');
                Log::channel('dear_saleinvoice')->info(json_encode($response));

                $fillResponse = $dear->SaleFulfilment()->create(['SaleID' => $saleId]);
                Log::channel('dear_saleinvoice')->info('SaleFulfilment');
                Log::channel('dear_saleinvoice')->info(json_encode($fillResponse));

                $pickResponse = $dear->SaleFulfilmentPick()->create($pickPackParam);
                Log::channel('dear_saleinvoice')->info('pickResponse');
                Log::channel('dear_saleinvoice')->info(json_encode($pickResponse));

                $packResponse = $dear->SaleFulfilmentPack()->create($pickPackParam);
                Log::channel('dear_saleinvoice')->info('packResponse');
                Log::channel('dear_saleinvoice')->info(json_encode($packResponse));

                $finalResponse = $dear->SaleFulfilmentShip()->create($shipParam);
                Log::channel('dear_saleinvoice')->info('shipResponse');
                Log::channel('dear_saleinvoice')->info(json_encode($shipParam));
            } catch(\Exception $e) {
                Log::channel('dear_saleinvoice')->info($e);
            }
        }
       
    }

      
    public function createDearSale(Request $request){
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
        $orderId = '';
        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        Log::channel('dear_saleinvoice')->info(json_encode($request->all()));
        $allData = $request->all();
        if($allData['event'] == 'onOrderCreated'){
            $orderId = $allData['payload']['OrderId'];

        }
        $dailyOrders = new DailyIconicOrders;
        $dailyOrders->order_id = $orderId;
        $dailyOrders->webhook_response = json_encode($request->all());
        $dailyOrders->save();
      
    }
    

    public function updateTracking(Request $request){
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
        $orderId = '8423677';
        $trackingNo= '11122233';
        $itemResponse = Endpoints::order()->getOrderItems($orderId)->call($client);
        if(isset($itemResponse->getBody()['OrderItems']['OrderItem']) && !empty($itemResponse->getBody()['OrderItems']['OrderItem'])){
            $orderItems = $itemResponse->getBody()['OrderItems']['OrderItem'];
            if(isset($orderItems['OrderItemId'])){
                $orderItemId = $orderItems['OrderItemId'];
                $data = Endpoints::order()->setStatusToReadyToShip([$orderItemId ],'dropship','AusPost',$trackingNo )->call($client);
                dd($data);
            }else{
                foreach($orderItems as $item){
                    $items[] = $item['OrderItemId'];
                }
                $data = Endpoints::order()->setStatusToReadyToShip($items,'dropship','AusPost',$trackingNo )->call($client);

        $logInfo = 'OrderId:'.$orderId.'trackingNo'.$trackingNo;
        Log::channel('webhooks')->info($logInfo);
        Log::channel('order_tracking')->info($logInfo);
        Log::channel('webhooks')->info('Change status into Iconic end');
        dd($data);
            }
        }
    }

    public function webhookResonse(Request $request){
        try{
            Log::channel('order_tracking')->info('Change Iconic status start');
            $json = json_encode($request->all());
            //Log::channel('webhooks')->info($json);
            $ss = $request->all();
            $resourceUrl = $request->get('resource_url');
            $resourceType = $request->get('resource_type');
            Log::channel('order_tracking')->info('ResourceUrl'.$resourceUrl);
            if($resourceType == 'SHIP_NOTIFY'){

                $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
                $response = $this->getWebhookResonse($resourceUrl);
                if(!empty($response)){
                    $responseData = json_decode($response);
                    if(count($responseData->shipments) > 0){
                        foreach ($responseData->shipments as $key => $value) {
                            $trackingNo = $value->trackingNumber;
                            $orderNumber = $value->orderNumber;
                            $orderId = '';
                            $existing = ShipstationOrders::where('iconic_order_number',$orderNumber)->first();
                            $orderId = $existing['iconic_order_id'];
                            if($orderId != ''){
                                Log::channel('order_tracking')->info('order id: '.$orderId);
                                $itemResponse = Endpoints::order()->getOrderItems($orderId)->call($client);
                                if(isset($itemResponse->getBody()['OrderItems']['OrderItem']) && !empty($itemResponse->getBody()['OrderItems']['OrderItem'])){
                                    $orderItems = $itemResponse->getBody()['OrderItems']['OrderItem'];
                                    Log::channel('order_tracking')->info('order Items Response');
                                    Log::channel('order_tracking')->info(json_encode($orderItems));
                                    if(isset($orderItems['OrderItemId'])){
                                        $orderItemId = $orderItems['OrderItemId'];
                                        $data = Endpoints::order()->setStatusToReadyToShip([$orderItemId ],'dropship','AusPost',$trackingNo )->call($client);
                                        $logInfo = 'OrderId:'.$orderId.' '.'trackingNo'.$trackingNo;
                                        $update = ShipstationOrders::where('iconic_order_number',$orderNumber)->update(['tracking_no'=> $trackingNo, 'tracking_no_updated' => '1']);

                                        //Log::channel('webhooks')->info($logInfo);
                                        Log::channel('order_tracking')->info($logInfo);
                                        Log::channel('order_tracking')->info($data);

                                    }else{
                                        foreach($orderItems as $item){
                                            $items[] = $item['OrderItemId'];
                                        }
                                        $data = Endpoints::order()->setStatusToReadyToShip($items,'dropship','AusPost',$trackingNo )->call($client);
                                        $update = ShipstationOrders::where('iconic_order_number',$orderNumber)->update(['tracking_no'=> $trackingNo, 'tracking_no_updated' => '1']);
                                        $logInfo = 'OrderId:'.$orderId.'trackingNo'.$trackingNo;
                                        Log::channel('webhooks')->info($logInfo);
                                        Log::channel('order_tracking')->info($logInfo);
                                        Log::channel('webhooks')->info('Change status into Iconic end');

                                    }
                                }
                            }else{
                                Log::channel('order_tracking')->info('orderId not found');
                            }

                        }
                    }
                }else{
                    Log::channel('webhooks')->info('Data from resource url not found');
                }
            }
    
        }catch(\Exception $e) {
            Log::channel('webhooks')->info($e->getMessage());
        }
        
    }
    public function addOrderIntoShipStation(){
        
        try{
            $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
            $data = Endpoints::order()->getOrder('11748023')->call($client);
            $orders = [];
            
            if(isset($data->getBody()['Orders']['Order']) && !empty($data->getBody()['Orders']['Order'])){
                $orders = $data->getBody()['Orders']['Order'];
                
                if(count($data->getBody()['Orders']) > 0){
                    if(isset($orders['OrderId'])){
                        $response = $this->addOrder($orders);
                    }else{
                        foreach ($orders as $order) {
                            $response = $this->addOrder($order);
                        }
                    }
                    
                    $info = $response->orderNumber.'order added successfully'; 
                    Log::channel('webhooks')->info($info);
                    if(isset($response->orderNumber)){
                        return Response::json(array('status' => 'OK','msg' => 'Order Added Successfully'), 200);
                    }
                }else{
                    return Response::json(array('status' => 'OK','msg' => 'Data not found'), 400);
                }
            }else{
                return Response::json(array('status' => 'OK','msg' => 'Data not found'), 400);
            }
        }catch(\Exception $e) {
            return Response::json(array('status' => 'error','msg' => $e->getMessage()), 400);
        }
    }
     public function addOrder($order){
        $orderData = $order;
        $countryList = config('constants.countryList');
        $orderId = $orderData['OrderId'];
       
        $shipStation = new ShipStation(getenv('SS_KEY'),getenv('SS_SECRET'),getenv('SS_API_URL'));
        
        $filter = array('orderStatus' => 'awaiting_shipment',
                        'orderNumber' => $orderData['OrderNumber']
                    );
        $ord = $shipStation->orders->get($filter);
        
        if($ord->total > 0){
            Log::channel('webhooks')->info('Order already exists:'.$orderData['OrderNumber']);
            return;
        }

        $orderItems = [];
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
        $data = Endpoints::order()->getOrderItems($orderId)->call($client);
        if(isset($data->getBody()['OrderItems']['OrderItem']) && !empty($data->getBody()['OrderItems']['OrderItem'])){
            $orderItems = $data->getBody()['OrderItems']['OrderItem'];  
        }
        
       
        $billingFullname = $orderData['AddressBilling']['FirstName'];
        if(isset($orderData['AddressBilling']['LastName']) && $orderData['AddressBilling']['LastName'] != ''){
            $billingFullname = $orderData['AddressBilling']['FirstName'].' '.$orderData['AddressBilling']['LastName'];
        }
        $billAddress = new Address;
        $billAddress->name  = $billingFullname;
        $billAddress->street1 = $orderData['AddressBilling']['Address1'];
        $billAddress->city = $orderData['AddressBilling']['City'];
        $billAddress->state = $orderData['AddressBilling']['Region'];
        $billAddress->postalCode = $orderData['AddressBilling']['PostCode'];
        $billAddress->country = array_search($orderData['AddressBilling']['Country'],$countryList);
        $billAddress->phone = $orderData['AddressBilling']['Phone'];
        $billAddress->customerEmail = $orderData['AddressBilling']['CustomerEmail'];

        $shippingFullname = $orderData['AddressShipping']['FirstName'];
        if(isset($orderData['AddressShipping']['LastName']) && $orderData['AddressShipping']['LastName'] != ''){
            $shippingFullname = $orderData['AddressShipping']['FirstName'].' '.$orderData['AddressShipping']['LastName'];
        }
        $shipAddress = new Address;
        $shipAddress->name  =  $shippingFullname;
        $shipAddress->street1 = $orderData['AddressShipping']['Address1'];
        $shipAddress->city = $orderData['AddressShipping']['City'];
        $shipAddress->state = $orderData['AddressShipping']['Region'];
        $shipAddress->postalCode = $orderData['AddressShipping']['PostCode'];
        $shipAddress->country = array_search($orderData['AddressShipping']['Country'], $countryList);
        $shipAddress->phone = $orderData['AddressShipping']['Phone'];
        $shipAddress->customerEmail = $orderData['AddressShipping']['CustomerEmail'];
        $taxAmount = 0;
       
        if(count($data->getBody()['OrderItems']) > 0){
            if(isset($orderItems['OrderItemId'])){
               $itemName = $orderItems['Name'];
                $products = Endpoints::product()->getProducts()->setSearch($orderItems['Sku'])->build()->call($client);
                if(count($products->getBody()['Products']) > 0){
                    $allProducts = $products->getBody()['Products']['Product'];
                    if(isset($allProducts['SellerSku'])){   
                        if($allProducts['Variation'] > '0'){
                            $itemName = $itemName.'-'.$allProducts['Variation'];
                        }
                        $itemName = $itemName.'-'.$allProducts['ProductData']['Color'];
                    }
                }
                $taxAmount = $orderItems['TaxAmount'];
                $item = new OrderItem;
                $item->sku = $orderItems['Sku'];
                $item->name = $itemName;
                $item->quantity = '1';
                $item->unitPrice  = $orderItems['ItemPrice'];
            }else{
                $orderItms = [];
                foreach($orderItems as $orderItem){
                  $itemName = $orderItem['Name'];
                    $products = Endpoints::product()->getProducts()->setSearch($orderItem['Sku'])->build()->call($client);
                    if(count($products->getBody()['Products']) > 0){
                        $allProducts = $products->getBody()['Products']['Product'];
                        if(isset($allProducts['SellerSku'])){   
                            if($allProducts['Variation'] > '0'){
                                $itemName = $itemName.'-'.$allProducts['Variation'];
                            }
                            $itemName = $itemName.'-'.$allProducts['ProductData']['Color'];
                        }
                    }

                    $taxAmount += $orderItem['TaxAmount'];
                    $item = new OrderItem;
                    $item->sku = $orderItem['Sku'];
                    $item->name = $itemName;
                    $item->quantity = '1';
                    $item->unitPrice  = $orderItem['ItemPrice'];
                    $orderItms[] = $item;
                }
            }
        }
        dd($orderItms);
        
        $amountPaid = $orderData['Price'] + $taxAmount;
        $order = new Order;
        $order->customerUsername = $orderData['CustomerFirstName'].''.$orderData['CustomerLastName'];
        $order->orderNumber =  $orderData['OrderNumber'];
        // $order->orderId = $orderData['OrderId'];
        $order->orderDate = $orderData['CreatedAt'];
        $order->orderStatus = 'awaiting_shipment';
        $order->amountPaid = $amountPaid;
        $order->taxAmount = $taxAmount;
        $order->shippingAmount = '0.00';
        // $order->internalNotes = 'A note about my order.';
        $order->billTo = $billAddress;
        $order->shipTo = $shipAddress;
        $order->items[] =$item;
        $order->paymentMethod = $orderData['PaymentMethod'];
         
        $response = $shipStation->orders->post($order, 'createorder');
        if(isset($response->orderNumber)){
            Log::channel('iconic_orders')->info('Order added into Ship Station start');
            $info = 'OrderNumber:'.$orderData['OrderNumber'];
            Log::channel('iconic_orders')->info($info);
            Log::channel('webhooks')->info($info);
            Log::channel('webhooks')->info('Order added into Ship Station end');
        }
        
        

    }
    public function getWebhookResonse($url){
        $str = getenv('SS_KEY').":".getenv('SS_SECRET');
        $authKey =  base64_encode($str);

        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => $url,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "GET",
          CURLOPT_HTTPHEADER => array(
            "Host: ssapi.shipstation.com",
            "Authorization: Basic " . $authKey,
          ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);
        Log::channel('order_tracking')->info('webhook response for Ship notify start');
        Log::channel('order_tracking')->info($response);
        Log::channel('order_tracking')->info('webhook response for Ship notify end');

        return $response;
    }

    public function createOrder(){

        $shipStation = new ShipStation(getenv('SS_KEY'),getenv('SS_SECRET'),getenv('SS_API_URL'));
        
        $address = new Address;
        $address->name = "Test User";
        $address->street1 = "123 Main St";
        $address->city = "Cleveland";
        $address->state = "OH";
        $address->postalCode = "44127";
        $address->country = "US";
        $address->phone = "2165555555";

        $item = new OrderItem;
        $item->lineItemKey = '1';
        $item->sku = '580123456';
        $item->name = "T-shirt";
        $item->quantity = '1';
        $item->unitPrice  = '29.99';
        $item->warehouseLocation = 'Warehouse A';

        $order = new Order;
        $order->orderNumber = '1';
        $order->orderDate = '2016-05-09';
        $order->orderStatus = 'awaiting_shipment';
        $order->amountPaid = '29.99';
        $order->taxAmount = '0.00';
        $order->shippingAmount = '0.00';
        $order->internalNotes = 'A note about my order.';
        $order->billTo = $address;
        $order->shipTo = $address;
        $order->items[] = $item;

        // This will var_dump the newly created order, and order should be wrapped in an array.
        $response = $shipStation->orders->post($order, 'createorder');
        dd($response);
        return Response::json(array('status' => 'OK','msg' =>'Order created Successfully'), 200);
    }

    public function getProductsFromIconic(){
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
        //$data = Endpoints::order()->getOrders(10)->setStatus('pending')->setSorting('created_at', 'ASC')->setLimit(12)->build()->call($client);
        $response = Endpoints::product()->getProducts()->setLimit(3)->build()->call($client);
        dd($response);

    }
    public function updateIconicOrderStatus(Request $request){
        // $orderId = $request->get('order_id');
        $trackingNo = '25825810';
    
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));

        $data = Endpoints::order()->getOrders(1)->setStatus('pending')->setSorting('created_at', 'ASC')->setLimit(1)->build()->call($client);
        
            $orders = [];
            if(isset($data->getBody()['Orders']['Order']) && !empty($data->getBody()['Orders']['Order'])){
                $orders = $data->getBody()['Orders']['Order'];

                if(count($data->getBody()['Orders']) > 1){
                    foreach ($orders as $order) {
                        //$this->addOrder($order);
                    }

                }else if(count($data->getBody()['Orders']) == 1){
                    $orderId = $data->getBody()['Orders']['Order']['OrderId'];
                    $response = Endpoints::order()->getOrderItems($orderId)->call($client);

                    if(isset($response->getBody()['OrderItems']['OrderItem']) && !empty($response->getBody()['OrderItems']['OrderItem'])){
                        $orderItems = $response->getBody()['OrderItems']['OrderItem'];
                        
                        if(isset($orderItems['OrderItemId'])){
                            $orderItemId = $orderItems['OrderItemId'];
                            $data = Endpoints::order()->setStatusToReadyToShip([$orderItemId ],'dropship','AusPost',$trackingNo )->call($client);
                        }else{echo "msafdskfjk";
                            foreach($orderItems as $item){
                                $items[] = $item['OrderItemId'];
                            }
                            $data = Endpoints::order()->setStatusToReadyToShip($items,'dropship','AusPost',$trackingNo )->call($client);
                        }
                        dd();
                        return Response::json(array('status' => 'OK','msg' =>'Order Status Updated Successfully'), 200); 
                        
                    }
                }
                
                return Response::json(array('status' => 'OK','msg' => 'Order Status Updated Successfully'), 200);
            }else{
                return Response::json(array('status' => 'OK','msg' => 'Data not found'), 400);
            }
        

    }

    public function getProductsFromDear(){
        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        //$data = ['SKU' => 'SK-B007'];
        //$data = array ('Page' => '1', 'Limit' => '5');
        $data = array ('Location' => 'The Iconic');
        //$products = $dear->product()->get($data);
        $products = $dear->ProductAvailability()->get($data);
        
        if(!empty($products['ProductAvailabilityList'])){
            $productId = $products['ProductAvailabilityList'][0]['ID'];
            $data2 = array ('Id' => $productId);
            $product = $dear->product()->get($data2);
            $product = $product['Products'][0];
        
            $productattachment = $dear->ProductAttachment()->get(['ProductID' => $productId]);
            
        }
        echo '<pre>';
        print_r($productattachment);
         dd($product);
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));

        //dd($client);
        $response = Endpoints::feed()->feedList()->call($client);
        //dd($response);

        $productCollectionRequest = Endpoints::product()->getCategoryTree()->call($client);
        //dd($data->getBody()['Categories']['Category']);
        $cat = $productCollectionRequest->getBody()['Categories']['Category'];
        //dd($cat);
        if(in_array('Clothing', $cat[0])){
            echo "ggggg";
        }else{
            echo "fffff";
        }
        //dd($cat[0]['Children']['Category']);
        

        $productCollectionRequest = Endpoints::product()->productCreate();
        $brand = 'ORTC'; // Please change the brand
        $primaryCategory = '10'; // Please change the primary category
        $sellerSku0 = $product['SKU'].'112'; // Please change SellerSku to your convenience
        $sellerSku1 = 'Api test product again'; // Please change SellerSku to your convenience
        
        $productCollectionRequest->newProduct()
        ->setName($product['Name'])
        ->setSellerSku($sellerSku0)
        ->setStatus($product['Status'])
        // ->setVariation('XXL')
        ->setPrimaryCategory($primaryCategory)
        ->setDescription($product['Description'])
        ->setBrand('ORTC')
        ->setPrice('87')
        ->setProductData(
            [   
                'ItemCategory' => 'Fashion',
                'Color' => 'Blue',
                'ColorNameBrand' => 'Indigo',
                'CustomerSegment' => 'Fashion',
                'Gender' => 'Male',
                'ProductGroupAttr' => 'Apparel',
                'Shop' => 'Curvy',
                'SkuSupplierConfig' => $sellerSku0,
                'WeightConfig' => $product['Weight'], 
                'Year' => '2019'
           ]);


        //dd($productCollectionRequest);
        $response = $productCollectionRequest->build()->call($client);
        

        if ($response instanceof ErrorResponse) {
            /** @var ErrorResponse $response */
            printf("ERROR !\n");
            printf("%s\n", $response->getMessage());
        } else {
            
                $response2 = Endpoints::product()->image($sellerSku0)
                ->addImage('https://inventory.dearsystems.com/ProductFamily/DownloadImage?id=17066bed-6ec4-43c7-90cc-2e0a6ee12af4')
                ->build()
                ->call($client);
            
            printf("The feed `%s` has been created.\n", $response->getFeedId());
             printf("The feed `%s` has been created.\n", $response2->getFeedId());
        
        }

         dd();
        // foreach($products['Products'] as $product){
        //  echo $product['DefaultLocation'].'<br>';
//
        // }

    }
    public function deleteOrder(){
        
        $shipStation = new ShipStation(getenv('SS_KEY'),getenv('SS_SECRET'),getenv('SS_API_URL'));
        $filter = array( 'orderNumber' => '282737897'
                    );
        $ord = $shipStation->orders->get($filter);
        dd( $ord);

        // $orderId = $shipStation->orders->get(['orderNumber' => '282737897'])->orders[0]->orderId;
        // // dd( $orderId);
        // $res = $shipStation->orders->delete($orderId);
        
        // dd($res);
    }

    public function dearWebhook(){
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
        $orderId = '';
        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        $json = '{"event":"onOrderCreated","payload":{"OrderId":8423776}}';
        $allData = json_decode($json);
        $orderId = $allData->payload->OrderId;
    
        Log::channel('dear_saleinvoice')->info('Iconic OrderId:'.$orderId);
        
        if($orderId != ''){
            $orderData = Endpoints::order()->getOrder($orderId)->call($client);
            // $order = $orderData->getBody()['Orders']['Order'];
            if(isset($orderData->getBody()['Orders']['Order']) && !empty($orderData->getBody()['Orders']['Order'])){
                $itemData = Endpoints::order()->getOrderItems($orderId)->call($client);
                
                if(isset($itemData->getBody()['OrderItems']['OrderItem']) && !empty($orderData->getBody()['Orders']['OrderItem'])){

                    $orderItems = $itemData->getBody()['OrderItems']['OrderItem'];
                    if(isset($orderItems['OrderItemId'])){  
                        $orderItems = $itemData->getBody()['OrderItems'];
                    }else{
                        $orderItems = $itemData->getBody()['OrderItems']['OrderItem'];
                    }
                    $sku = '';
                    $arr = [];
                    foreach($orderItems as $item){
                        if($sku = ''){
                            $sku = $item['Sku'];
                        }else{
                            if($sku == $item['Sku']){
                                $arr[$sku][] = [ 
                                                "SKU"  => $item['Sku'],
                                                "Name" => $item['Name'],
                                                "Price" => $item['PaidPrice'],
                                                "Tax" => $item['TaxAmount'],
                                                "TaxRule" => "Tax on Sales",
                                                "Total"   => $item['PaidPrice']
                                            ];
                            }else{
                                $sku = $item['Sku'];
                                $arr[$sku][] =  [ 
                                                "SKU"  => $item['Sku'],
                                                "Name" => $item['Name'],
                                                "Price" => $item['PaidPrice'],
                                                "Tax" => $item['TaxAmount'],
                                                "TaxRule" => "Tax on Sales",
                                                "Total"   => $item['PaidPrice']
                                            ];
                            }
                        }
                    }
                    $customerResponse = $dear->Customer()->get(['Name' => 'Test']);
                    $customer = $customerResponse['CustomerList'][0];
                    $randomCode = substr(md5(microtime()),rand(0,26),6);
                    $parameters = [
                                        "CustomerID" => $customer['ID'],
                                        "Location" => $customer['Location'],
                                        "CurrencyRate" => "1",
                                        //"Terms" => "Proforma", 
                                        //"PriceTier" => "Retail - Standard",
                                        "Location" => "Iconic test",
                                       //"DefaultAccount" => "2003: Iconic Sales",
                                        "TaxRule" => "Tax on Sales",
                                    ];
                    $saleResponse = $dear->sale()->create($parameters);
                    $saleId = $saleResponse['ID'];
                    foreach($arr as $key => $value){
                        $qty = count($value);
                        $total = $qty*$value[0]['Price'];
                        $orderLine[] =  array(
                                            "SKU"  => $value[0]['SKU'],
                                            "Name" => $value[0]['Name'],
                                            "Quantity" => $qty,
                                            "Price" => $value[0]['Price'],
                                            "Tax" => $value[0]['Tax'],
                                            "TaxRule" => "Tax on Sales",
                                            "Total"   => $total,
                                        );
                        $pickPackLine[] =  array(
                                            "SKU" => $value[0]['SKU'],
                                            "Name" => $value[0]['Name'],
                                            "Location" => "Iconic test",
                                            "LocationID" => "7423f294-95ea-44b1-a5b5-18ad50873754",
                                            "Quantity" => $qty,
                                            "Box"   => "test box",
                                            "BatchSN" => "PO-00001-1",
                                            "ExpiryDate" => "2020-11-30T00:00:00Z",
                                            "ShipmentDate" => "2020-10-23T00:00:00Z",
                                            "Carrier" => "Post",
                                            "IsShipped" => true  
                                        );
                    }
                    $parameters = [
                                    "SaleID" => $saleId,
                                    "CombineAdditionalCharges" => '0',
                                    "Memo" => 'test sale order',
                                    "Status" => "AUTHORISED",
                                    "Lines"  => $orderLine,
                                ];
                
                    $pickPackParam =  [
                                    "TaskID" => $saleId,
                                    "Status" => "AUTHORISED",
                                    "AutoPickPackShipMode" => "AUTOPICKPACKSHIP",
                                    "Lines"  => $pickPackLine,
                                ];
                    $response = $dear->SaleOrder()->create($parameters);
                    $pickResponse = $dear->SaleFulfilment()->create(['SaleID' => $saleId]);
                    $pickResponse = $dear->SaleFulfilmentPick()->create($pickPackParam);
                    $packResponse = $dear->SaleFulfilmentPack()->create($pickPackParam);
                    $finalResponse = $dear->SaleFulfilmentShip()->create($pickPackParam);

                    Log::channel('dear_saleinvoice')->info('SaleId:'.$saleId);
                }else{
                    Log::channel('dear_saleinvoice')->info('Order Item fot found');
                }
            }
        }else{
            Log::channel('dear_saleinvoice')->info('OrderId not found');
        }

    }
    public function createDearWebhook(){

        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        //Get all webhook
        $webhooks = $dear->Webhooks()->get([]);
        dd($webhooks);
        //delete webhook
        $response =  $dear->webhooks()->delete('164205e3-c609-4c26-9126-abd337789378', []);
        //dd($response);
        $randomCode = substr(md5(microtime()),rand(0,26),6);
        
        $webhookParams =  array(
                                "Type" => "Stock/AvailableStockLevelChanged",
                                "IsActive" => true,
                                "ExternalURL" => "http://209.124.64.222/inventory-shipstation/public/update-stock",
                            "Name" => "Sale/OrderAuthorised $randomCode",
                                "ExternalAuthorizationType" => "basicauth",
                                "ExternalUserName" => "$randomCode",
                                "ExternalPassword" => "$randomCode",
                                "ExternalBearerToken" => ""
                            );
         $response = $dear->webhooks()->create($webhookParams);
         dd($response);

    }
    public function createLocation(){
        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        $randomCode = substr(md5(microtime()),rand(0,26),6);
        $params = [
                "Name" => "Iconic test"
            ];
        $response = $dear->Location()->create($params);
        $response = $dear->Location()->get([]);
        dd($response);
    }
    public function dearResonse(Request $request){
        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        //$response = $dear->StockAdjustmentList()->get([]);
        //dd($response);
        // echo 'ffff';
        // dd();
        //  Log::channel('webhooks')->info($request->all());
  //        Log::channel('webhooks')->info('Dear Response');
        // $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
        // $productCollectionRequest = Endpoints::product()->productUpdate();

        // $productCollectionRequest->updateProduct('SK-SS005112')->setName('Product Name Again Changed');

        // $response = $productCollectionRequest->build()->call($client);
        // dd($response);

        $json = '[{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"City Showroom","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":0,"Allocated":0,"Available":0,"OnOrder":0,"StockOnHand":0,"MaxRows":9},{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"Iconic test","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":20,"Allocated":0,"Available":20,"OnOrder":0,"StockOnHand":490.4083,"MaxRows":9},{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"Iconic test 6e4872","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":0,"Allocated":0,"Available":0,"OnOrder":0,"StockOnHand":0,"MaxRows":9},{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"Iconic test ef165b","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":0,"Allocated":0,"Available":0,"OnOrder":0,"StockOnHand":0,"MaxRows":9},{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"Main Warehouse","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":100,"Allocated":0,"Available":100,"OnOrder":0,"StockOnHand":2443.965,"MaxRows":9},{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"Mega Mart","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":0,"Allocated":0,"Available":0,"OnOrder":0,"StockOnHand":0,"MaxRows":9},{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"Production_Hall","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":0,"Allocated":0,"Available":0,"OnOrder":0,"StockOnHand":0,"MaxRows":9},{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"Super Store","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":0,"Allocated":0,"Available":0,"OnOrder":0,"StockOnHand":0,"MaxRows":9},{"ID":"8669db72-8762-4b31-9666-6eb3065dff95","SKU":"Glasses11","Name":"Carrera Glasses","Barcode":"989123245646","Location":"Warehouse","Bin":null,"Batch":null,"ExpiryDate":null,"Category":"Apparel","OnHand":0,"Allocated":0,"Available":0,"OnOrder":0,"StockOnHand":0,"MaxRows":9}]';
        
        $data = json_decode($json);
        dd($data);
        foreach ($data as $key => $value) {
            if(isset($value->Location) && $value->Location == 'Iconic test'){
                $productData = $value;
                $sku = $productData->SKU;
                echo $availableQuantity = $productData->Available;
                $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
                $productCollectionRequest = Endpoints::product()->productUpdate();
                $productCollectionRequest->updateProduct('sku-002')->setQuantity($availableQuantity);
                $response = $productCollectionRequest->build()->call($client);
                dd($response);
            }
            
        }
        dd();
        $json = json_encode($request->all());
        Log::channel('webhooks')->info($json);
        Log::channel('webhooks')->info('Dear Response');
        
    }
    public function dearWebhookResonse(Request $request){
        $json = json_encode($request->all());
        Log::channel('dear_response')->info($json);
        Log::channel('dear_response')->info('Dear Webhook Response');
    }
    
    public function createDearInvoices()
   {
    $SC_API_URL = "https://sellercenter-api.theiconic.com.au";
    $SC_API_USER = "charlie@ortc.com.au";
    $SC_API_KEY =  "cd66941749d8f133e4e333619ea5c625184e7e45";
    $client = Client::create(new Configuration($SC_API_URL, $SC_API_USER, $SC_API_KEY));
    dd($client);

   }

    public function testShipOrderUpload(){

        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
            $data = Endpoints::order()->getOrders(10)->setStatus('pending')->setSorting('created_at', 'ASC')->build()->call($client);
           
            $orders = [];
            if(isset($data->getBody()['Orders']['Order']) && !empty($data->getBody()['Orders']['Order'])){
                $orders = $data->getBody()['Orders']['Order'];
               
                if(count($data->getBody()['Orders']) > 0){
                    if(isset($orders['OrderId'])){
                        $this->addOrderTest($orders);
                    }else{
                        foreach ($orders as $order) {
                            $this->addOrderTest($order);
                        }
                    }

                }else{
                    
                }
                
                
            }else{
                 
            }
        
    }

    public function addOrderTest($order){

         $orderData = $order;
         $countryList = config('constants.countryList');
         $orderId = $orderData['OrderId'];
      
        
        // $existingOrderCount = ShipstationOrders::where(['iconic_order_id' => $orderId])->count();
       
        // if($existingOrderCount == 0){
            // $newOrderAtShip = new ShipstationOrders;
            // $newOrderAtShip->iconic_order_number = $orderData['OrderNumber'];
            // $newOrderAtShip->iconic_order_id = $orderData['OrderId'];
            // $newOrderAtShip->save();

$shipStation = new ShipStation(getenv('SS_KEY'),getenv('SS_SECRET'),getenv('SS_API_URL'));
            // $shipStation = new ShipStation('75682d5ca9f0475cbc8f9e77ae7a5d66','b460db4f782a4c14a0fa050482e879bf',getenv('SS_API_URL'));
            
            
            $filter = array('orderStatus' => 'awaiting_shipment',
                            'orderNumber' => $orderData['OrderNumber']
                        );
            $ord = $shipStation->orders->get($filter);
            
            if($ord->total > 0){
                Log::channel('webhooks')->info('Order already exists:'.$orderData['OrderNumber']);
                return;
            }
           
            $orderItems = [];
            $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
            $data = Endpoints::order()->getOrderItems($orderId)->call($client);
         
            if(isset($data->getBody()['OrderItems']['OrderItem']) && !empty($data->getBody()['OrderItems']['OrderItem'])){
                $orderItems = $data->getBody()['OrderItems']['OrderItem'];  
            }
            
           
            $billingFullname = $orderData['AddressBilling']['FirstName'];
            if(isset($orderData['AddressBilling']['LastName']) && $orderData['AddressBilling']['LastName'] != ''){
                $billingFullname = $orderData['AddressBilling']['FirstName'].' '.$orderData['AddressBilling']['LastName'];
            }
            
            $billAddress = new Address;
            $billAddress->name  = $billingFullname;
            $billAddress->street1 = $orderData['AddressBilling']['Address1'];
            $billAddress->city = $orderData['AddressBilling']['City'];
            $billAddress->state = $orderData['AddressBilling']['Region'];
            $billAddress->postalCode = $orderData['AddressBilling']['PostCode'];
            $billAddress->country = array_search($orderData['AddressBilling']['Country'],$countryList);
            $billAddress->phone = $orderData['AddressBilling']['Phone'];
            $billAddress->customerEmail = $orderData['AddressBilling']['CustomerEmail'];

            $shippingFullname = $orderData['AddressShipping']['FirstName'];
            if(isset($orderData['AddressShipping']['LastName']) && $orderData['AddressShipping']['LastName'] != ''){
                $shippingFullname = $orderData['AddressShipping']['FirstName'].' '.$orderData['AddressShipping']['LastName'];
            }
            $shipAddress = new Address;
            $shipAddress->name  =  $shippingFullname;
            $shipAddress->street1 = $orderData['AddressShipping']['Address1'];
            $shipAddress->city = $orderData['AddressShipping']['City'];
            $shipAddress->state = $orderData['AddressShipping']['Region'];
            $shipAddress->postalCode = $orderData['AddressShipping']['PostCode'];
            $shipAddress->country = array_search($orderData['AddressShipping']['Country'], $countryList);
            $shipAddress->phone = $orderData['AddressShipping']['Phone'];
            $shipAddress->customerEmail = $orderData['AddressShipping']['CustomerEmail'];
            $taxAmount = 0;
            $orderItms = [];
            if(count($data->getBody()['OrderItems']) > 0){
                if(isset($orderItems['OrderItemId'])){
                   $itemName = $orderItems['Name'];
                    $products = Endpoints::product()->getProducts()->setSearch($orderItems['Sku'])->build()->call($client);
                    if(count($products->getBody()['Products']) > 0){
                        $allProducts = $products->getBody()['Products']['Product'];
                        if(isset($allProducts['SellerSku'])){   
                            if($allProducts['Variation'] > '0'){
                                $itemName = $itemName.'-'.$allProducts['Variation'];
                            }
                            $itemName = $itemName.'-'.$allProducts['ProductData']['Color'];
                        }
                    }
                    $taxAmount = $orderItems['TaxAmount'];
                    $item = new OrderItem;
                    $item->sku = $orderItems['Sku'];
                    $item->name = $itemName;
                    $item->quantity = '1';
                    $item->unitPrice  = $orderItems['ItemPrice'];
                    $orderItms[] = $item;
                }else{
                    foreach($orderItems as $orderItem){
                      $itemName = $orderItem['Name'];
                        $products = Endpoints::product()->getProducts()->setSearch($orderItem['Sku'])->build()->call($client);
                        if(count($products->getBody()['Products']) > 0){
                            $allProducts = $products->getBody()['Products']['Product'];
                            if(isset($allProducts['SellerSku'])){   
                                if($allProducts['Variation'] > '0'){
                                    $itemName = $itemName.'-'.$allProducts['Variation'];
                                }
                                $itemName = $itemName.'-'.$allProducts['ProductData']['Color'];
                            }
                        }

                        $taxAmount += $orderItem['TaxAmount'];
                        $item = new OrderItem;
                        $item->sku = $orderItem['Sku'];
                        $item->name = $itemName;
                        $item->quantity = '1';
                        $item->unitPrice  = $orderItem['ItemPrice'];
                        $orderItms[] = $item;
                    }
                }
            }
            
            
            $amountPaid = $orderData['Price'] + $taxAmount;
            $order = new Order;
            $order->customerUsername = $orderData['CustomerFirstName'].''.$orderData['CustomerLastName'];
            $order->orderNumber =  $orderData['OrderNumber'];
            // $order->orderId = $orderData['OrderId'];
            $order->orderDate = $orderData['CreatedAt'];
            $order->orderStatus = 'awaiting_shipment';
            $order->amountPaid = $amountPaid;
            $order->taxAmount = $taxAmount;
            $order->shippingAmount = '0.00';
            // $order->internalNotes = 'A note about my order.';
            $order->billTo = $billAddress;
            $order->shipTo = $shipAddress;
            $order->items = $orderItms;
            $order->paymentMethod = $orderData['PaymentMethod'];
           
           $response = $shipStation->orders->post($order, 'createorder');
              
            if(isset($response->orderNumber)){
                $newOrderAtShip->is_uploaded = '1';
                $newOrderAtShip->update();

                Log::channel('iconic_orders')->info('Order added into Ship Station start');
                $info = 'OrderNumber:'.$orderData['OrderNumber'];
                Log::channel('iconic_orders')->info($info);
                Log::channel('webhooks')->info($info);
                Log::channel('webhooks')->info('Order added into Ship Station end');
            }

        //}
       
    }

public function testOrder() {

	$shipStation = new ShipStation('75682d5ca9f0475cbc8f9e77ae7a5d66','b460db4f782a4c14a0fa050482e879bf',getenv('SS_API_URL'));
	$orderTest = array(

  "orderNumber" => "TEST-ORDER-API-DOCS",
  "orderId" => "3245",
  // "orderKey" => "0f6bec18-3e89-4881-83aa-f392d84f4c74",
  "orderDate" => "2015-06-29T08:46:27.0000000",
  // "paymentDate" => "2015-06-29T08:46:27.0000000",
  // "shipByDate" => "2015-07-05T00:00:00.0000000",
  "orderStatus" => "awaiting_shipment",
  // "customerId" => 37701499,
  "customerUsername" => "headhoncho@whitehouse.gov",
  "customerEmail" => "headhoncho@whitehouse.gov",
  "billTo" => array(
    "name" => "The President",
    "company" => null,
    "street1" => "1600 Pennsylvania Ave",
    "street2" => null,
    "street3" => null,
    "city" => "Washington",
    "state" => "DC",
    "postalCode" => "20500",
    "country" => "US",
    "phone" => "555-555-5555",
    // "residential" => null
  ),
  "shipTo" => array(
    "name" => "The President",
    // "company" => "US Govt",
    "street1" => "1600 Pennsylvania Ave",
    // "street2" => "Oval Office",
    // "street3" => null,
    "city" => "Washington",
    "state" => "DC",
    "postalCode" => "20500",
    "country" => "US",
    "phone" => "555-555-5555",
    // "residential" => true
  ),
  "items"=> [
    [
      // "lineItemKey" => "vd08-MSLbtx",
      "sku" => "ABC123",
      "name" => "Test item #1",
      // "imageUrl" => null,
      // "weight" => [
        // "value" => 24,
        // "units" => "ounces"
      // ],
      "quantity" => 2,
      "unitPrice" => 99.99,
      // "taxAmount" => 2.5,
      // "shippingAmount" => 5,
      // "warehouseLocation" => "Aisle 1, Bin 7",
      // "options" => [
        // [
          // "name" => "Size",
          // "value" => "Large"
        // ]
      // ],
      // "productId" => 123456,
      // "fulfillmentSku" => null,
      // "adjustment" => false,
      // "upc" => "32-65-98"
    ],
    [
      // "lineItemKey" => null,
      "sku" => "DISCOUNT CODE",
      "name" => "10% OFF",
      // "imageUrl" => null,
      // "weight" => [
        // "value" => 0,
        // "units" => "ounces"
      // ],
      "quantity" => 1,
      "unitPrice" => -20.55,
      // "taxAmount" => null,
      // "shippingAmount" => null,
      "warehouseLocation" => null,
      // "options" => [],
      // "productId" => 123456,
      // "fulfillmentSku" => "SKU-Discount",
      // "adjustment" => true,
      // "upc" => null
    ]
  ],
  "amountPaid" => 218.73,
  "taxAmount" => 5,
  "shippingAmount" => 10,
  // "customerNotes" => "Please ship as soon as possible!",
  // "internalNotes" => "Customer called and would like to upgrade shipping",
  // "gift" => true,
  // "giftMessage" => "Thank you!",
  "paymentMethod" => "Credit Card",
  // "requestedShippingService" => "Priority Mail",
  // "shipDate" => "2015-07-02",
  // "weight" => [
    // "value" => 25,
    // "units" => "ounces"
  // ],
  // "dimensions" => [
    // "units" => "inches",
    // "length" => 7,
    // "width" => 5,
    // "height" => 6
  // ],
  // "insuranceOptions" => [
    // "provider" => "carrier",
    // "insureShipment" => true,
    // "insuredValue" => 200
  // ],
  // "internationalOptions" => [
    // "contents" => null,
    // "customsItems" => null
  // ],

  // "tagIds" => [
    // 53974
  // ]
);
        $response = $shipStation->orders->post($orderTest, 'createorder');
	dd($response);
             die;
}

}
// https://inventory.dearsystems.com/ProductFamily/DownloadImage?id=ef8cf00e-3ccc-43c0-ae78-655e8a3a6a68
// https://inventory.dearsystems.com/ProductFamily/DownloadImage?id=ef8cf00e-3ccc-43c0-ae78-655e8a3a6a68
// https://inventory.dearsystems.com/ProductFamily/DownloadImage?id=4c8bb8d9-39b7-4f8f-95bf-0b4617cc3aa7
