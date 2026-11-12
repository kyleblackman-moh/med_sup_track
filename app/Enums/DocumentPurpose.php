<?php

namespace App\Enums;

/** procurement_type_documents.purpose (and, later, uploaded documents). Values are lowercase. */
enum DocumentPurpose: string
{
    case original_rfq = 'original_rfq';
    case supplier_quotations = 'supplier_quotations';
    case evaluation_report = 'evaluation_report';
    case technical_evidence = 'technical_evidence';
    case signed_request = 'signed_request';
    case stock_evidence = 'stock_evidence';
    case product_specifications = 'product_specifications';
    case rha_sample_confirmation = 'rha_sample_confirmation';
    case supplier_correspondence = 'supplier_correspondence';
    case supplier_confirmation = 'supplier_confirmation';
    case tender_pricing_confirmation = 'tender_pricing_confirmation';
    case purchase_orders_delivery = 'purchase_orders_delivery';
    case outstanding_order_status = 'outstanding_order_status';
    case other_supporting = 'other_supporting';
}
