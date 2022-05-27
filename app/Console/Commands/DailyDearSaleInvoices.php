<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use UmiMood\Dear\Dear;
use App\DailyIconicOrders;
use RocketLabs\SellerCenterSdk\Core\Client;
use RocketLabs\SellerCenterSdk\Endpoint\Endpoints;
use RocketLabs\SellerCenterSdk\Core\Configuration;
use Illuminate\Support\Facades\Log;

class DailyDearSaleInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dailyDearInvoices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create daily Iconic Invoices into Dear Inventory';

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
}
