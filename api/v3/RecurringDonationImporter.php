<?php

function civicrm_api3_recurring_donation_importer_create($params) {
  try {
    $importer = new CRM_Membershipextrasimporterapi_RecurringDonation_CSVRowImporter($params);
    $importer->import();
  }
  catch (Exception $exception) {
    return civicrm_api3_create_error($exception->getMessage());
  }

  return civicrm_api3_create_success(1, $params);
}

function _civicrm_api3_recurring_donation_importer_create_spec(&$params) {
  // Contact fields
  $params['contact_id'] = [
    'title' => 'Contact Id',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['contact_external_id'] = [
    'title' => 'Contact External Id',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['first_name'] = [
    'title' => 'First Name',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['last_name'] = [
    'title' => 'Last Name',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['email'] = [
    'title' => 'Email',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  // Recurring Contribution fields
  $params['recurring_contribution_external_id'] = [
    'title' => 'Recurring Contribution External Id',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['recurring_contribution_amount'] = [
    'title' => 'Recurring Contribution Amount',
    'type' => CRM_Utils_Type::T_MONEY,
    'api.required' => 1,
  ];

  $params['recurring_contribution_currency'] = [
    'title' => 'Recurring Contribution Currency',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['recurring_contribution_frequency_unit'] = [
    'title' => 'Recurring Contribution Frequency Unit',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['recurring_contribution_cycle_day'] = [
    'title' => 'Recurring Contribution Cycle Day',
    'type' => CRM_Utils_Type::T_INT,
  ];

  $params['recurring_contribution_start_date'] = [
    'title' => 'Recurring Contribution Start Date',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['recurring_contribution_next_sched_date'] = [
    'title' => 'Recurring Contribution Next Scheduled Date',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];

  $params['recurring_contribution_financial_type'] = [
    'title' => 'Recurring Contribution Financial Type',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['recurring_contribution_payment_instrument'] = [
    'title' => 'Recurring Contribution Payment Instrument',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  $params['recurring_contribution_description'] = [
    'title' => 'Recurring Contribution Description',
    'type' => CRM_Utils_Type::T_STRING,
  ];

  // GoCardless fields
  $params['gocardless_mandate_id'] = [
    'title' => 'GoCardless Mandate Id',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => 1,
  ];
}
