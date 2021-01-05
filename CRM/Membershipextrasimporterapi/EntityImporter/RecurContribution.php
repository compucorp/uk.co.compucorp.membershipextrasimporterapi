<?php

/**
 * Imports the recurring contribution record.
 *
 * Class CRM_Membershipextrasimporterapi_EntityImporter_RecurContribution
 */
class CRM_Membershipextrasimporterapi_EntityImporter_RecurContribution {

  private $rowData;

  private $contactId;

  private $cachedValues;

  public function __construct($rowData, $contactId) {
    $this->rowData = $rowData;
    $this->contactId = $contactId;
  }

  public function import() {
    $recurContributionId = $this->getRecurContributionIdIfExist();
    if ($recurContributionId) {
      return $recurContributionId;
    }

    $sqlParams = $this->prepareSqlParams();
    $sqlQuery = "INSERT INTO `civicrm_contribution_recur` (`contact_id` , `amount` , `currency` , `frequency_unit` , `frequency_interval` , `installments` ,
            `start_date`, `contribution_status_id`, `payment_processor_id` , `financial_type_id` , `payment_instrument_id`, `auto_renew`, `create_date`,
            `next_sched_contribution_date`, `cycle_day`) 
            VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9 ,%10, %11, %12, %13, %14, %15)";
    CRM_Core_DAO::executeQuery($sqlQuery, $sqlParams);

    $dao = CRM_Core_DAO::executeQuery('SELECT LAST_INSERT_ID() as recur_contribution_id');
    $dao->fetch();
    $recurContributionId = $dao->recur_contribution_id;

    $sqlQuery = "INSERT INTO `civicrm_value_contribution_recur_ext_id` (`entity_id` , `external_id`) 
           VALUES ({$recurContributionId}, %1)";
    CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->rowData['payment_plan_external_id'], 'String']]);

    return $recurContributionId;
  }

  private function getRecurContributionIdIfExist() {
    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_contribution_recur_ext_id WHERE external_id = %1";
    $recurContributionId = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->rowData['payment_plan_external_id'], 'String']]);
    $recurContributionId->fetch();

    if (!empty($recurContributionId->id)) {
      return $recurContributionId->id;
    }

    return NULL;
  }

  /**
   * Prepares the sql parameters
   * that will be used to create recur
   * contribution record.
   *
   * @return array
   *
   * @throws CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException
   */
  private function prepareSqlParams() {
    $amount = $this->getAmount();
    $currency = $this->getCurrency();
    $frequencyParams = $this->calculateFrequencyParameters();
    $recurContributionStatusId = $this->getRecurContributionStatusId();
    $paymentProcessorId = $this->getPaymentProcessorId();
    $financialTypeId = $this->getFinancialTypeId();
    $paymentMethodId = $this->getPaymentMethodId();
    $isAutoRenew = $this->isAutoRenew();
    $cycleDay = $this->getCycleDay($frequencyParams['unit']);
    $startDate = $this->formatRowDate('payment_plan_start_date', 'Start Date');
    $createDate = $this->formatRowDate('payment_plan_create_date', 'Create Date');
    $nextContributionDate = $this->formatRowDate('payment_plan_next_contribution_date', 'Next Contribution Date', TRUE);

    return [
      1 => [$this->contactId, 'Integer'],
      2 => [$amount, 'Money'],
      3 => [$currency, 'String'],
      4 => [$frequencyParams['unit'], 'String'],
      5 => [$frequencyParams['interval'], 'Integer'],
      6 => [$frequencyParams['installments_count'], 'Integer'],
      7 => [$startDate, 'String'],
      8 => [(int) $recurContributionStatusId, 'Integer'],
      9 => [(int) $paymentProcessorId, 'Integer'],
      10 => [(int) $financialTypeId, 'Integer'],
      11 => [(int) $paymentMethodId, 'Integer'],
      12 => [$isAutoRenew, 'Integer'],
      13 => [$createDate, 'String'],
      14 => [$nextContributionDate, 'String'],
      15 => [(int) $cycleDay, 'Integer'],
    ];
  }

  private function getAmount() {
    if (!empty($this->rowData['payment_plan_total_amount'])) {
      $amount = $this->rowData['payment_plan_total_amount'];
    }
    else {
      // todo : if null we default this to the total amount of the instalment with the most future date. but how to do that ?
    }

    return $amount;
  }

  private function getCurrency() {
    // todo : not in mapping document, need to discuss with others how to get it.
    return 'GBP';
  }

  private function calculateFrequencyParameters() {
    switch ($this->rowData['payment_plan_frequency']) {
      case 'month':
        $frequencyParameters['unit'] = 'month';
        $frequencyParameters['interval'] = 1;
        $frequencyParameters['installments_count'] = 12;
        break;

      case 'year':
        $frequencyParameters['unit'] = 'year';
        $frequencyParameters['interval'] = 1;
        $frequencyParameters['installments_count'] = 1;
        break;

      default:
        throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Payment Plan Frequency should be either "month" or "year"', 100);
    }

    return $frequencyParameters;
  }

  private function getRecurContributionStatusId() {
    $statusName = 'Completed';
    if (!empty($this->rowData['payment_plan_status'])) {
      $statusName = $this->rowData['payment_plan_status'];
    }

    if (!isset($this->cachedValues['recur_contribution_statuses'])) {
      $sqlQuery = "SELECT cov.name as name, cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'contribution_recur_status'";
      $result = CRM_Core_DAO::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['recur_contribution_statuses'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['recur_contribution_statuses'][$statusName])) {
      return $this->cachedValues['recur_contribution_statuses'][$statusName];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Invalid payment plan "Status"', 200);
  }

  private function getPaymentProcessorId() {
    // todo: later to add validation to ensure that direct debit fields exist if the payment processor is 'direct debit'.
    if (!isset($this->cachedValues['payment_processors'])) {
      $sqlQuery = "SELECT id, name FROM civicrm_payment_processor WHERE is_test = 0";
      $result = CRM_Core_DAO::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['payment_processors'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['payment_processors'][$this->rowData['payment_plan_payment_processor']])) {
      return $this->cachedValues['payment_processors'][$this->rowData['payment_plan_payment_processor']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Invalid payment plan "Payment Processor"', 300);
  }

  private function getFinancialTypeId() {
    if (!isset($this->cachedValues['financial_types'])) {
      $sqlQuery = "SELECT id, name FROM civicrm_financial_type";
      $result = CRM_Core_DAO::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['financial_types'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['financial_types'][$this->rowData['payment_plan_financial_type']])) {
      return $this->cachedValues['financial_types'][$this->rowData['payment_plan_financial_type']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Invalid payment plan "Financial Type"', 400);
  }

  private function getPaymentMethodId() {
    $sqlQuery = "SELECT cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'payment_instrument'  AND cov.name = %1";
    $result = CRM_Core_DAO::executeQuery($sqlQuery, [1 => [$this->rowData['payment_plan_payment_method'], 'String']]);

    if ($result->fetch()) {
      return $result->id;
    }

    if (!isset($this->cachedValues['payment_methods'])) {
      $sqlQuery = "SELECT cov.name as name, cov.value as id FROM civicrm_option_value cov 
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id 
                  WHERE cog.name = 'payment_instrument'";
      $result = CRM_Core_DAO::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['payment_methods'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['payment_methods'][$this->rowData['payment_plan_payment_method']])) {
      return $this->cachedValues['payment_methods'][$this->rowData['payment_plan_payment_method']];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Invalid payment plan "Payment Method"', 500);
  }

  private function isAutoRenew() {
    $autoRenew = 0;
    if (!empty($this->rowData['payment_plan_auto_renew']) && $this->rowData['payment_plan_auto_renew'] == 1) {
      $autoRenew = 1;
    }

    return $autoRenew;
  }

  private function getCycleDay($frequencyUnit) {
    if ($frequencyUnit == 'year') {
      return NULL;
    }

    if ($frequencyUnit == 'month' && empty($this->rowData['payment_plan_cycle_day'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Cycle day is required field for monthly payment plans', 600);
    }

    if ($frequencyUnit == 'month' && ($this->rowData['payment_plan_cycle_day'] < 1 || $this->rowData['payment_plan_cycle_day'] >= 28)) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Payment Plan Cycle day must be positive integer that is less than 28', 700);
    }

    return $this->rowData['payment_plan_cycle_day'];
  }

  private function formatRowDate($dateColumnName, $columnLabel, $isRequired = FALSE) {
    if ($isRequired && empty($this->rowData[$dateColumnName])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException("Payment Plan '{$columnLabel}' is required field.", 800);
    }

    if (!empty($this->rowData[$dateColumnName])) {
      $date = DateTime::createFromFormat('YmdHis', $this->rowData[$dateColumnName]);
      $date = $date->format('Y-m-d');
    }
    else {
      $date = new DateTime();
      $date = $date->format('Y-m-d');
    }

    return $date;
  }

}
