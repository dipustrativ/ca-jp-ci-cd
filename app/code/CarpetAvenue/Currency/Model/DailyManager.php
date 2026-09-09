<?php
namespace CarpetAvenue\Currency\Model;

class DailyManager implements \CarpetAvenue\Currency\Api\DailyManagerInterface{

    protected \Magento\Framework\App\ResourceConnection $resourceConnection;
    public function __construct(\Magento\Framework\App\ResourceConnection $resourceConnection){
        $this->resourceConnection = $resourceConnection;
    }
    public function setDailyFromGenesis(): void{
        $allDates = $this->getDatesFromGenesis();
        $xmlAllDays = simplexml_load_file('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml');
        //$xmlAllDays = simplexml_load_file('/var/www/html/releases/current/magento/var/importexport/eurofxref-hist.xml');
        $this->processDates($allDates,$xmlAllDays->Cube->Cube);
    }
    public function setDailyIncremental(): void{
        $incrementalDates = $this->getDatesIncremental();
        $xml90Days = simplexml_load_file('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml');
        //$xml90Days = simplexml_load_file('/var/www/html/releases/current/magento/var/importexport/eurofxref-hist-90d.xml');
        $this->processDates($incrementalDates,$xml90Days->Cube->Cube);
    }
    protected function processDates($dbDates, $xmlDayRates): void{
        $count = count($xmlDayRates);
        $i = 0;
        $currencies = $this->getCurrencies();
        foreach($dbDates as $dbDate){
            // Always set EUR to 1.0
            $dbDateString = substr($dbDate['created_at'],0,10);
            $this->updateRateForDay($dbDateString, 'EUR', 1.0);
            $dbTime = strtotime($dbDate['created_at']);
            while($i<$count){
                $xmlDayRate = $xmlDayRates[$i];
                $xmlDate = $xmlDayRate->attributes()->time;
                $xmlTime = strtotime($xmlDate);
                if ($dbTime >= $xmlTime){
                    foreach($xmlDayRate->Cube as $dayRate){
                        if ($this->activeCurrency($dayRate->attributes()->currency, $currencies)){
                            $this->updateRateForDay($dbDateString, $dayRate->attributes()->currency, $dayRate->attributes()->rate[0]);
                        }
                    }
                    break;
                }
                $i++;
            }
            if ($i==$count){
                break;
            }
        }
    }
    protected function activeCurrency($currency, $activeCurrencies): bool{
        //if ($currency != 'SEK'){return false;}
        foreach($activeCurrencies as $activeCurrency){
            if ($activeCurrency['currency'] == $currency){
                return true;
            }
        }
        return false;
    }
    protected function updateRateForDay($date,$currency,$rate): void{
        $time = strtotime($date);
        $year = date('Y',$time);
        $month = date('m',$time);
        $day = date('d',$time);
        $connection = $this->resourceConnection->getConnection();

        $query = "update sales_order so".
                    " set so.store_to_eur_rate=".$rate.
                    " where so.order_currency_code='".$currency."'".
                    " and year(so.created_at)=".$year.
                    " and month(so.created_at)=".$month.
                    " and day(so.created_at)=".$day;
        $connection->query($query);

        $query = "update sales_invoice si".
                    " set si.store_to_eur_rate=".$rate.
                    " where si.order_currency_code='".$currency."'".
                    " and year(si.created_at)=".$year.
                    " and month(si.created_at)=".$month.
                    " and day(si.created_at)=".$day;
        $connection->query($query);

        $query = "update sales_creditmemo sc".
                    " set sc.store_to_eur_rate=".$rate.
                    " where sc.order_currency_code='".$currency."'".
                    " and year(sc.created_at)=".$year.
                    " and month(sc.created_at)=".$month.
                    " and day(sc.created_at)=".$day;
        $connection->query($query);
    }

    protected function getDatesFromGenesis(): array{
        $connection = $this->resourceConnection->getConnection();
        return $connection->fetchAll("select distinct concat(year(created_at),'-',lpad(month(created_at),2,0),'-',lpad(day(created_at),2,0)) as created_at from sales_order order by created_at desc");
    }

    protected function getDatesIncremental(): array{
        $connection = $this->resourceConnection->getConnection();
        return $connection->fetchAll("select distinct concat(year(created_at),'-',lpad(month(created_at),2,0),'-',lpad(day(created_at),2,0)) as created_at from sales_order order by created_at desc limit 7");
    }

    protected function getCurrencies(): array{
        $connection = $this->resourceConnection->getConnection();
        return $connection->fetchAll("select distinct order_currency_code as currency from sales_order");
    }
}