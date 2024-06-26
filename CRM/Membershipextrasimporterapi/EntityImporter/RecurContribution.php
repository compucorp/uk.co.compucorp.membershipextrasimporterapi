<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;

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
    $this->validateIfDirectDebitPaymentPlan();
    $this->validateIfEitherPaymentSchemeOrPaymentFrequencyIsSet();

    $recurContributionId = $this->getRecurContributionIdIfExist();
    if ($recurContributionId) {
      $this->updateExistingRecurContribution($recurContributionId);
    }
    else {
      $recurContributionId = $this->createNewRecurContribution();
    }

    $this->setExtraAttributesCustomGroupValues($recurContributionId);

    return $recurContributionId;
  }

  private function updateExistingRecurContribution($recurContributionId) {
    $sqlParams = $this->prepareSqlParams();
    $sqlParams[15] = [$recurContributionId, 'Integer'];
    $sqlQuery = "UPDATE `civicrm_contribution_recur` SET
                `contact_id` = %1, `currency` = %2, `frequency_unit` = %3, `frequency_interval` = %4,
                `installments` = %5, `start_date` = %6, `contribution_status_id` = %7, `payment_processor_id` = %8,
                `financial_type_id` = %9, `payment_instrument_id` = %10, `auto_renew` = %11, `create_date` = %12,
                `next_sched_contribution_date` = %13, `cycle_day` = %14
                WHERE id = %15";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

  private function createNewRecurContribution() {
    $sqlParams = $this->prepareSqlParams();
    $sqlParams[15] = [0, 'Integer'];
    $sqlQuery = "INSERT INTO `civicrm_contribution_recur` (`contact_id`, `currency` , `frequency_unit` , `frequency_interval` , `installments` ,
            `start_date`, `contribution_status_id`, `payment_processor_id` , `financial_type_id` , `payment_instrument_id`, `auto_renew`, `create_date`,
            `next_sched_contribution_date`, `cycle_day`, `amount`)
            VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9 ,%10, %11, %12, %13, %14, %15)";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as recur_contribution_id');
    $dao->fetch();
    $recurContributionId = $dao->recur_contribution_id;

    $sqlQuery = "INSERT INTO `civicrm_value_contribution_recur_ext_id` (`entity_id` , `external_id`)
           VALUES ({$recurContributionId}, %1)";
    SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['payment_plan_external_id'], 'String']]);

    return $recurContributionId;
  }

  private function getRecurContributionIdIfExist() {
    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_contribution_recur_ext_id WHERE external_id = %1";
    $recurContributionId = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['payment_plan_external_id'], 'String']]);
    $recurContributionId->fetch();

    if (!empty($recurContributionId->id)) {
      return $recurContributionId->id;
    }

    return NULL;
  }

  private function validateIfDirectDebitPaymentPlan() {
    $isPaymentProcessorDirectDebit = ($this->rowData['payment_plan_payment_processor'] == 'Direct Debit');
    $isPaymentMethodDirectDebit = ($this->rowData['payment_plan_payment_method'] == 'direct_debit');
    if ($isPaymentProcessorDirectDebit  && !$isPaymentMethodDirectDebit) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Payment plan payment method should be direct debit if the payment processor is direct debit', 1000);
    }

    if (!$isPaymentProcessorDirectDebit  && $isPaymentMethodDirectDebit) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Payment plan payment processor should be direct debit if the payment method is direct debit', 1100);
    }
  }

  /**
   * Payment plan instalments should be either paid using
   * a payment scheme, or using one of the traditional
   * instalment frequencies (monthly, quarterly, annual).
   * Furthermore, If the latter is used then the "Next contribution date"
   * should be available too.
   *
   * @return void
   */
  private function validateIfEitherPaymentSchemeOrPaymentFrequencyIsSet() {
    if (empty($this->rowData['payment_plan_payment_scheme_id']) && empty($this->rowData['payment_plan_frequency'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('The Payment plan payment scheme id or alternatively the payment plan frequency should be set.', 1300);
    }

    if (!empty($this->rowData['payment_plan_frequency']) && empty($this->rowData['payment_plan_next_contribution_date'])) {
      throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('The payment plan next contribution data is required for non payment scheme payment plans.', 1400);
    }
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
    $nextContributionDate = $this->formatRowDate('payment_plan_next_contribution_date', 'Next Contribution Date');

    return [
      1 => [$this->contactId, 'Integer'],
      2 => [$currency, 'String'],
      3 => [$frequencyParams['unit'], 'String'],
      4 => [$frequencyParams['interval'], 'Integer'],
      5 => [$frequencyParams['installments_count'], 'Integer'],
      6 => [$startDate, 'String'],
      7 => [(int) $recurContributionStatusId, 'Integer'],
      8 => [(int) $paymentProcessorId, 'Integer'],
      9 => [(int) $financialTypeId, 'Integer'],
      10 => [(int) $paymentMethodId, 'Integer'],
      11 => [$isAutoRenew, 'Integer'],
      12 => [$createDate, 'String'],
      13 => [$nextContributionDate, 'String'],
      14 => [(int) $cycleDay, 'Integer'],
    ];
  }

  private function getCurrency() {
    if (!isset($this->cachedValues['currencies_enabled'])) {
      $sqlQuery = "SELECT cov.name as name, LOWER(cov.value) as value, cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'currencies_enabled'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['currencies_enabled'][$result->value] = $result->id;
      }
    }

    if (!empty($this->cachedValues['currencies_enabled'][strtolower($this->rowData['payment_plan_currency'])])) {
      return $this->cachedValues['currencies_enabled'][strtolower($this->rowData['payment_plan_currency'])];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Invalid or disabled payment plan "currency"', 900);
  }

  private function calculateFrequencyParameters() {
    if (!empty($this->rowData['payment_plan_payment_scheme_id'])) {
      // defaulting these parameters to 1 year if payment scheme
      // is used, but in reality whatever values we use here
      // it won't matter, given these values are not used
      // with payment plans linked to payment schemes.
      $frequencyParameters['unit'] = 'year';
      $frequencyParameters['interval'] = 1;
      $frequencyParameters['installments_count'] = 1;

      return $frequencyParameters;
    }

    switch ($this->rowData['payment_plan_frequency']) {
      case 'monthly':
        $frequencyParameters['unit'] = 'month';
        $frequencyParameters['interval'] = 1;
        $frequencyParameters['installments_count'] = 12;
        break;

      case 'quarterly':
        $frequencyParameters['unit'] = 'month';
        $frequencyParameters['interval'] = 3;
        $frequencyParameters['installments_count'] = 4;
        break;

      case 'annual':
        $frequencyParameters['unit'] = 'year';
        $frequencyParameters['interval'] = 1;
        $frequencyParameters['installments_count'] = 1;
        break;

      default:
        throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Payment Plan Frequency should be "monthly", "quarterly" or "annual"', 100);
    }

    return $frequencyParameters;
  }

  private function getRecurContributionStatusId() {
    $statusName = 'completed';
    if (!empty($this->rowData['payment_plan_status'])) {
      $statusName = strtolower($this->rowData['payment_plan_status']);
    }

    if (!isset($this->cachedValues['recur_contribution_statuses'])) {
      $sqlQuery = "SELECT LOWER(cov.name) as name, cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'contribution_recur_status'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['recur_contribution_statuses'][$result->name] = $result->id;
      }
    }

    if (!empty($this->cachedValues['recur_contribution_statuses'][$statusName])) {
      return $this->cachedValues['recur_contribution_statuses'][$statusName];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Invalid payment plan "Status"', 200);
  }

  /**
   * Returns the payment processor id.
   * Hence that only payment processors
   * that implements Payment_Manual class
   * are allowed, since the importer will
   * only be used with offline payment
   * processors.
   *
   * @return mixed
   *
   * @throws CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException
   */
  private function getPaymentProcessorId() {
    if (!isset($this->cachedValues['payment_processors'])) {
      $sqlQuery = "SELECT id, name, class_name FROM civicrm_payment_processor WHERE is_test = 0 AND is_active = 1";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        $this->cachedValues['payment_processors'][$result->name] = ['id' => $result->id, 'class_name' => $result->class_name];
      }
    }

    $paymentProcessorName = $this->rowData['payment_plan_payment_processor'];
    if (!empty($this->cachedValues['payment_processors'][$paymentProcessorName])) {
      $supportedPaymentProcessorClassname = ['Payment_Manual', 'Payment_GoCardless'];
      if (!in_array($this->cachedValues['payment_processors'][$paymentProcessorName]['class_name'], $supportedPaymentProcessorClassname)) {
        throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Only GoCardless and Manual payment plan "Payment Processors"', 1200);
      }

      return $this->cachedValues['payment_processors'][$paymentProcessorName]['id'];
    }

    throw new CRM_Membershipextrasimporterapi_Exception_InvalidRecurContributionFieldException('Invalid payment plan "Payment Processor"', 300);
  }

  private function getFinancialTypeId() {
    if (!isset($this->cachedValues['financial_types'])) {
      $sqlQuery = "SELECT id, name FROM civicrm_financial_type";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
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
    if (!isset($this->cachedValues['payment_methods'])) {
      $sqlQuery = "SELECT cov.name as name, cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'payment_instrument'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
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

  private function setExtraAttributesCustomGroupValues($recurContributionId) {
    $isActive = 0;
    if (!empty($this->rowData['payment_plan_is_active'])) {
      $isActive = 1;
    }

    $paymentSchemeId = 'NULL';
    if (!empty($this->rowData['payment_plan_payment_scheme_id'])) {
      $paymentSchemeId = $this->rowData['payment_plan_payment_scheme_id'];
    }

    $activationQuery = "
      INSERT INTO civicrm_value_payment_plan_extra_attributes
      (entity_id, is_active, payment_scheme_id) VALUES ({$recurContributionId}, {$isActive}, {$paymentSchemeId})
      ON DUPLICATE KEY UPDATE is_active = {$isActive}, payment_scheme_id = {$paymentSchemeId}
     ";
    SQLQueryRunner::executeQuery($activationQuery);
  }

}
