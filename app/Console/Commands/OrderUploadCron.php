<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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

use App\WebhooksResponse;
use App\ShipstationOrders;

class OrderUploadCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order_upload:cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        
        Log::channel('webhooks')->info('Order upload is working fine!');
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
            $data   = Endpoints::order()->getOrders(10)->setStatus('pending')->setSorting('created_at', 'ASC')->build()->call($client);
            $orders = [];
            if (isset($data->getBody()['Orders']['Order']) && !empty($data->getBody()['Orders']['Order'])) {
                $orders = $data->getBody()['Orders']['Order'];
               // Log::channel('webhooks')->info('Pending orders from Iconic start');
                //Log::channel('webhooks')->info(json_encode($data->getBody()['Orders']));
                //Log::channel('webhooks')->info('Pending orders from Iconic end');
                if (count($data->getBody()['Orders']) > 0) {
                    if (isset($orders['OrderId'])) {
                        $this->addOrder($orders);
                    } else {
                        foreach ($orders as $order) {
                            $this->addOrder($order);
                        }
                    }
                } else {
                    Log::channel('webhooks')->info('Order not found');
                }
                
                
            } else {
                Log::channel('webhooks')->info('Order data not found');
            }
        $this->info('Order Upload Cron Command Run successfully!');
    }
    public function addOrder($order){
        $orderData   = $order;
        $countryList = config('constants.countryList');
        $orderId     = $orderData['OrderId'];
        
        $existingOrderCount = ShipstationOrders::where(['iconic_order_id' => $orderId])->count();
        if ($existingOrderCount == 0) {
            $newOrderAtShip                      = new ShipstationOrders;
            $newOrderAtShip->iconic_order_number = $orderData['OrderNumber'];
            $newOrderAtShip->iconic_order_id     = $orderData['OrderId'];
            $newOrderAtShip->save();

            $shipStation = new ShipStation(getenv('SS_KEY'),getenv('SS_SECRET'),getenv('SS_API_URL'));
            
            $filter = array('orderStatus' => 'awaiting_shipment',
                            'orderNumber' => $orderData['OrderNumber']
                        );
            $ord = $shipStation->orders->get($filter);
            
            if ($ord->total > 0) {
                Log::channel('webhooks')->info('Order already exists:'.$orderData['OrderNumber']);
                return;
            }

            $orderItems = [];
            $client     = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));
            $data       = Endpoints::order()->getOrderItems($orderId)->call($client);
            if (isset($data->getBody()['OrderItems']['OrderItem']) && !empty($data->getBody()['OrderItems']['OrderItem'])) {
                $orderItems = $data->getBody()['OrderItems']['OrderItem'];  
            }
            
           
            $billingFullname = $orderData['AddressBilling']['FirstName'];
            if (isset($orderData['AddressBilling']['LastName']) && $orderData['AddressBilling']['LastName'] != '') {
                $billingFullname = $orderData['AddressBilling']['FirstName'].' '.$orderData['AddressBilling']['LastName'];
            }
            $billAddress                = new Address;
            $billAddress->name          = $billingFullname;
            $billAddress->street1       = $orderData['AddressBilling']['Address1'];
            $billAddress->city          = $orderData['AddressBilling']['City'];
            $billAddress->state         = $orderData['AddressBilling']['Region'];
            $billAddress->postalCode    = $orderData['AddressBilling']['PostCode'];
            $billAddress->country       = array_search($orderData['AddressBilling']['Country'],$countryList);
            $billAddress->phone         = $orderData['AddressBilling']['Phone'];
            $billAddress->customerEmail = $orderData['AddressBilling']['CustomerEmail'];

            $shippingFullname = $orderData['AddressShipping']['FirstName'];
            if (isset($orderData['AddressShipping']['LastName']) && $orderData['AddressShipping']['LastName'] != '') {
                $shippingFullname = $orderData['AddressShipping']['FirstName'].' '.$orderData['AddressShipping']['LastName'];
            }
            $shipAddress                = new Address;
            $shipAddress->name          =  $shippingFullname;
            $shipAddress->street1       = $orderData['AddressShipping']['Address1'];
            $shipAddress->city          = $orderData['AddressShipping']['City'];
            $shipAddress->state         = $orderData['AddressShipping']['Region'];
            $shipAddress->postalCode    = $orderData['AddressShipping']['PostCode'];
            $shipAddress->country       = array_search($orderData['AddressShipping']['Country'], $countryList);
            $shipAddress->phone         = $orderData['AddressShipping']['Phone'];
            $shipAddress->customerEmail = $orderData['AddressShipping']['CustomerEmail'];

            $taxAmount = 0;
            $orderItms = [];

            if (count($data->getBody()['OrderItems']) > 0) {
                if (isset($orderItems['OrderItemId'])) {
                    $itemName = $orderItems['Name'];
                    $products = Endpoints::product()->getProducts()->setSearch($orderItems['Sku'])->build()->call($client);
                    if (count($products->getBody()['Products']) > 0) {
                        $allProducts = $products->getBody()['Products']['Product'];
                        if (isset($allProducts['SellerSku'])) {   
                            if ($allProducts['Variation'] > '0') {
                                $itemName = $itemName.'-'.$allProducts['Variation'];
                            }
                            $itemName = $itemName.'-'.$allProducts['ProductData']['Color'];
                        }
                    }
                    $taxAmount        = $orderItems['TaxAmount'];
                    $item             = new OrderItem;
                    $item->sku        = $orderItems['Sku'];
                    $item->name       = $itemName;
                    $item->quantity   = '1';
                    $item->unitPrice  = $orderItems['ItemPrice'];
                    $orderItms[]      = $item;
                } else {
                    foreach ($orderItems as $orderItem) {
                        $itemName = $orderItem['Name'];
                        $products = Endpoints::product()->getProducts()->setSearch($orderItem['Sku'])->build()->call($client);
                        if (count($products->getBody()['Products']) > 0) {
                            $allProducts = $products->getBody()['Products']['Product'];
                            if (isset($allProducts['SellerSku'])) {   
                                if ($allProducts['Variation'] > '0') {
                                    $itemName = $itemName.'-'.$allProducts['Variation'];
                                }
                                $itemName = $itemName.'-'.$allProducts['ProductData']['Color'];
                            }
                        }

                        $taxAmount += $orderItem['TaxAmount'];
                        $item             = new OrderItem;
                        $item->sku        = $orderItem['Sku'];
                        $item->name       = $itemName;
                        $item->quantity   = '1';
                        $item->unitPrice  = $orderItem['ItemPrice'];
                        $orderItms[]      = $item;
                    }
                }
            }
            
            
            $amountPaid = $orderData['Price'] + $taxAmount;
            $order = new Order;
            $order->customerUsername = $orderData['CustomerFirstName'].''.$orderData['CustomerLastName'];
            $order->orderNumber      =  $orderData['OrderNumber'];
             // $order->orderId = $orderData['OrderId'];
            $order->orderDate        = $orderData['CreatedAt'];
            $order->orderStatus      = 'awaiting_shipment';
            $order->amountPaid       = $amountPaid;
            $order->taxAmount        = $taxAmount;
            $order->shippingAmount   = '0.00';
            // $order->internalNotes = 'A note about my order.';
            $order->billTo           = $billAddress;
            $order->shipTo           = $shipAddress;
            $order->items            = $orderItms;
            $order->paymentMethod    = $orderData['PaymentMethod'];
             
            $response = $shipStation->orders->post($order, 'createorder');
            if (isset($response->orderNumber)) {
                $newOrderAtShip->is_uploaded = '1';
                $newOrderAtShip->update();

                Log::channel('iconic_orders')->info('Order added into Ship Station start');
                $info = 'OrderNumber:'.$orderData['OrderNumber'];
                Log::channel('iconic_orders')->info($info);
                Log::channel('webhooks')->info($info);
                Log::channel('webhooks')->info('Order added into Ship Station end');
            }

        }
    }
}
