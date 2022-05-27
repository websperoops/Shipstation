<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
       Commands\OrderUploadCron::class,
       Commands\DailyDearSaleInvoices::class,
       Commands\UpdateIconicStock::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
    
        $schedule->command('order_upload:cron')->everyFiveMinutes();
        $schedule->command('dailyDearInvoices')->dailyAt('00:01');
        $schedule->command('update:stock')->hourly();
     //  $schedule->command('dailyDearInvoices')->everyMinute();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
