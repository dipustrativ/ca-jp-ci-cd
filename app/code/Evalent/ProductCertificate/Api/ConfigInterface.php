<?php


namespace Evalent\ProductCertificate\Api;


interface ConfigInterface
{
    const SALES_PDF_COA_PRODUCTION_TYPE = 'sales_pdf/coa/production_type';
    const SALES_PDF_COA_ROW_PRICE_LIMIT = 'sales_pdf/coa/row_price_limit';
    const SALES_PDF_COA_INCLUDE_SIGNATURE = 'sales_pdf/coa/include_signature';
    const SALES_PDF_COA_FOOTER_TEXT = 'sales_pdf/coa/footer_text';

    /**
     * @param $id
     * @param $storeId
     * @return bool
     */
    public function isValidProductionType($id, $storeId): bool;

    /**
     * @param $storeId
     * @return float
     */
    public function getRowPriceLimit($storeId): float;

    /**
     * @param $storeId
     * @return bool
     */
    public function includeSignature($storeId): bool;

    /**
     * @param $storeId
     * @return string|null
     */
    public function getFooterText($storeId): ?string;
}