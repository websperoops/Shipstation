<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use UmiMood\Dear\Dear;
use RocketLabs\SellerCenterSdk\Core\Client;
use RocketLabs\SellerCenterSdk\Core\Configuration;
use RocketLabs\SellerCenterSdk\Endpoint\Endpoints;
use RocketLabs\SellerCenterSdk\Core\Request\GenericRequest;
use RocketLabs\SellerCenterSdk\Core\Response\ErrorResponse;
use RocketLabs\SellerCenterSdk\Core\Response\SuccessResponseInterface;
use Illuminate\Support\Facades\Log;

class UpdateIconicStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:stock';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Iconic products stock availability from Dear';

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
        $msg = 'Iconic Stock update Start ';
        Log::channel('iconic_stockupdate')->info($msg);

        $dear = Dear::create(getenv('DI_ID'), getenv('DI_KEY'));
        $client = Client::create(new Configuration(getenv('SC_API_URL'), getenv('SC_API_USER'), getenv('SC_API_KEY')));

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
}
