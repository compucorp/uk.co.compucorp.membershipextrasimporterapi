<?php

function civicrm_api3_membershipextras_importer_create($params) {
  try {
    $importer = new CRM_Membershipextrasimporterapi_CSVRowImporter($params);
    $importer->import();
  }
  catch (Exception $exception) {
    return civicrm_api3_create_error($exception->getMessage());
  }

  return civicrm_api3_create_success(1, $params);
}

function _civicrm_api3_membershipextras_importer_create_spec(&$params) {
  $params['contact_id'] = [
    'title' => 'Contact Id',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['contact_external_id'] = [
    'title' => 'Contact External Id',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  // Recur Contribution (Payment Plan)
  $params['payment_plan_external_id'] = [
    'title' => 'Payment Plan External Id',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['payment_plan_payment_processor'] = [
    'title' => 'Payment Plan Payment Processor',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['payment_plan_currency'] = [
    'title' => 'Payment Plan Currency',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['payment_plan_frequency'] = [
    'title' => 'Payment Plan Frequency',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['payment_plan_next_contribution_date'] = [
    'title' => 'Payment Plan Next Contribution Date',
    'type' => CRM_Utils_Type::T_DATE,
    'api.required' => 1,
  ];

  $params['payment_plan_start_date'] = [
    'title' => 'Payment Plan Start Date',
    'type' => CRM_Utils_Type::T_DATE,
  ];

  $params['payment_plan_create_date'] = [
    'title' => 'Payment Plan Create Date',
    'type' => CRM_Utils_Type::T_DATE,
  ];

  $params['payment_plan_cycle_day'] = [
    'title' => 'Payment Plan Cycle Day',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['payment_plan_auto_renew'] = [
    'title' => 'Payment Plan Auto Renew?',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['payment_plan_financial_type'] = [
    'title' => 'Payment Plan Financial Type',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['payment_plan_status'] = [
    'title' => 'Payment Plan Status',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['payment_plan_payment_method'] = [
    'title' => 'Payment Plan Payment Method',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['payment_plan_is_active'] = [
    'title' => 'Payment Plan Is Active?',
    'type' => CRM_Utils_Type::T_INT,
  ];

  // Membership
  $params['membership_external_id'] = [
    'title' => 'Membership External Id',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['membership_type'] = [
    'title' => 'Membership Type',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['membership_join_date'] = [
    'title' => 'Membership Join Date',
    'type' => CRM_Utils_Type::T_DATE,
  ];

  $params['membership_start_date'] = [
    'title' => 'Membership Start Date',
    'type' => CRM_Utils_Type::T_DATE,
  ];

  $params['membership_end_date'] = [
    'title' => 'Membership End Date',
    'type' => CRM_Utils_Type::T_DATE,
  ];

  $params['membership_status'] = [
    'title' => 'Membership Status',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['membership_is_status_overridden'] = [
    'title' => 'Membership Status Overridden?',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['membership_status_override_end_date'] = [
    'title' => 'Membership Status Override End Date',
    'type' => CRM_Utils_Type::T_DATE,
  ];

  // Contribution
  $params['contribution_external_id'] = [
    'title' => 'Contribution External Id',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['contribution_financial_type'] = [
    'title' => 'Contribution Financial Type',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['contribution_currency'] = [
    'title' => 'Contribution Currency',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['contribution_payment_method'] = [
    'title' => 'Contribution Payment Method',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['contribution_received_date'] = [
    'title' => 'Contribution Received Date',
    'type' => CRM_Utils_Type::T_DATE,
    'api.required' => 1,
  ];

  $params['contribution_status'] = [
    'title' => 'Contribution Status',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['contribution_invoice_number'] = [
    'title' => 'Contribution Invoice Number',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  // Line Item
  $params['line_item_entity_table'] = [
    'title' => 'Order Line Entity Type',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['line_item_entity_id'] = [
    'title' => 'Order Line Entity Id',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['line_item_label'] = [
    'title' => 'Order Line Label',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['line_item_quantity'] = [
    'title' => 'Order Line Quantity',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['line_item_unit_price'] = [
    'title' => 'Order Line Unit Price',
    'type' => CRM_Utils_Type::T_MONEY,
    'api.required' => 1,
  ];

  $params['line_item_financial_type'] = [
    'title' => 'Order Line Financial Type',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['line_item_tax_amount'] = [
    'title' => 'Order Line Tax Amount',
    'type' => CRM_Utils_Type::T_MONEY,
  ];

  $params['line_item_auto_renew'] = [
    'title' => 'Order Line Auto Renew',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['line_item_price_field_id'] = [
    'title' => 'Price Field Id',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['line_item_price_field_value_id'] = [
    'title' => 'Price Field value Id',
    'type' => CRM_Utils_Type::T_INT,
  ];

  // Direct Debit Mandate
  $params['direct_debit_mandate_reference'] = [
    'title' => 'Direct Debit Mandate Reference',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['direct_debit_mandate_bank_name'] = [
    'title' => 'Direct Debit Mandate Bank Name',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['direct_debit_mandate_account_holder'] = [
    'title' => 'Direct Debit Mandate Account Holder Name',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['direct_debit_mandate_account_number'] = [
    'title' => 'Direct Debit Mandate Account Number',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['direct_debit_mandate_sort_code'] = [
    'title' => 'Direct Debit Mandate Sort Code',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['direct_debit_mandate_code'] = [
    'title' => 'Direct Debit Mandate Code',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['direct_debit_mandate_start_date'] = [
    'title' => 'Direct Debit Mandate Start Date',
    'type' => CRM_Utils_Type::T_DATE,
  ];

  $params['direct_debit_mandate_originator_number'] = [
    'title' => 'Direct Debit Mandate Originator Number',
    'type' => CRM_Utils_Type::T_STRING,
  ];
}
