<?php
namespace CarpetAvenue\Currency\Model;

class AverageManager implements \CarpetAvenue\Currency\Api\AverageManagerInterface{

    protected \CarpetAvenue\Currency\Model\AverageRatesFactory $averageRatesFactory;
    protected \CarpetAvenue\Currency\Model\ResourceModel\AverageRates $averageRatesResource;

    /**
     * @param \CarpetAvenue\Currency\Model\AverageRatesFactory $averageRatesFactory
     * @param \CarpetAvenue\Currency\Model\ResourceModel\AverageRates $averageRatesResource
     */
    public function __construct(
        \CarpetAvenue\Currency\Model\AverageRatesFactory $averageRatesFactory,
        \CarpetAvenue\Currency\Model\ResourceModel\AverageRates $averageRatesResource
    ){
        $this->averageRatesFactory = $averageRatesFactory;
        $this->averageRatesResource = $averageRatesResource;
    }

    /**
     * @inheritDoc
     */
    public function updateRate($id,$rate): void {
        $average = $this->averageRatesFactory->create();
        $this->averageRatesResource->load($average,$id,'id');
        $average->setRate($rate);
        $this->averageRatesResource->save($average);
        $connection = $this->averageRatesResource->getConnection();
        $query = "update sales_order so".
                    " set so.store_to_eur_rate=".$average->getRate().
                    " where so.order_currency_code='".$average->getCurrencyTo()."'".
                    " and year(so.created_at)=".$average->getYear().
                    " and month(so.created_at)=".$average->getMonth();
        $connection->query($query);

        $query = "update sales_invoice si".
                    " set si.store_to_eur_rate=".$average->getRate().
                    " where si.order_currency_code='".$average->getCurrencyTo()."'".
                    " and year(si.created_at)=".$average->getYear().
                    " and month(si.created_at)=".$average->getMonth();
        $connection->query($query);

        $query = "update sales_creditmemo sc".
                    " set sc.store_to_eur_rate=".$average->getRate().
                    " where sc.order_currency_code='".$average->getCurrencyTo()."'".
                    " and year(sc.created_at)=".$average->getYear().
                    " and month(sc.created_at)=".$average->getMonth();
        $connection->query($query);
    }

    public function insertAverageCurrencyRows(): void {
        $connection = $this->averageRatesResource->getConnection();
        $query = "select distinct".
                " 'EUR' as currency_from".
                " ,so.order_currency_code as currency_to".
                " ,dcr.rate as rate".
                " ,concat(year(so.created_at),'-',lpad(month(so.created_at),2,'0'),'-01 12:00:00') as rate_time".
                " from sales_order so".
                " join directory_currency_rate dcr on dcr.currency_to=so.order_currency_code and dcr.currency_from='EUR'".
                " left join carpet_currency_rates ccr on ccr.currency_to=so.order_currency_code and year(ccr.rate_time)=year(so.created_at) and month(ccr.rate_time)=month(so.created_at)".
                " where ccr.rate is null";
        
        foreach($connection->fetchAll($query) as $rate){
            $average = $this->averageRatesFactory->create();
            $average->setCurrencyFrom($rate['currency_from']);
            $average->setCurrencyTo($rate['currency_to']);
            $average->setRate($rate['rate']);
            $average->setRateTime($rate['rate_time']);
            $this->averageRatesResource->save($average);
        }
    }

    /**
     * @inheritDoc
     */
    public function getUnsyncedData(): array{
        $connection = $this->averageRatesResource->getConnection();
        $query = "select".
                " so.order_currency_code".
                ",year(ccr.rate_time) as year".
                ",month(ccr.rate_time) as month".
                " from carpet_currency_rates ccr".
                " join sales_order so on year(so.created_at)=year(ccr.rate_time) and month(so.created_at)=month(ccr.rate_time) and so.order_currency_code=ccr.currency_to".
                " where so.store_to_eur_rate<>ccr.rate".
                " group by".
                " so.order_currency_code".
                ",year(ccr.rate_time)".
                ",month(ccr.rate_time)".
                "";
        return $connection->fetchAll($query);
    }
}