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

  $params['payment_plan_total_amount'] = [
    'title' => 'Payment Plan Total Amount',
    'type' => CRM_Utils_Type::T_MONEY,
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

}
