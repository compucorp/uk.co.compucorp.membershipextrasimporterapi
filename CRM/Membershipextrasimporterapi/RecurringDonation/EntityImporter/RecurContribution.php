<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;
use CRM_Membershipextrasimporterapi_RecurringDonation_Cache_OptionValueCache as OptionValueCache;
use CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException as InvalidFieldException;

/**
 * Imports the recurring contribution record for recurring donations.
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_RecurContribution {

  private $rowData;

  private $contactId;

  public function __construct($rowData, $contactId) {
    $this->rowData = $rowData;
    $this->contactId = $contactId;
  }

  /**
   * Imports or updates the recurring contribution.
   *
   * @return int
   *   The recurring contribution ID.
   */
  public function import() {
    $recurContributionId = $this->getRecurContributionIdIfExist();
    if ($recurContributionId) {
      $this->updateExistingRecurContribution($recurContributionId);
    }
    else {
      $recurContributionId = $this->createNewRecurContribution();
    }

    return $recurContributionId;
  }

  private function updateExistingRecurContribution($recurContributionId) {
    $sqlParams = $this->prepareSqlParams();
    $sqlParams[13] = [$recurContributionId, 'Integer'];
    $sqlQuery = "UPDATE `civicrm_contribution_recur` SET
                `contact_id` = %1, `currency` = %2, `frequency_unit` = %3, `frequency_interval` = %4,
                `start_date` = %5, `contribution_status_id` = %6, `payment_processor_id` = %7,
                `financial_type_id` = %8, `payment_instrument_id` = %9,
                `cycle_day` = %10, `amount` = %11, `next_sched_contribution_date` = %12
                WHERE id = %13";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);
  }

  private function createNewRecurContribution() {
    $sqlParams = $this->prepareSqlParams();
    $sqlQuery = "INSERT INTO `civicrm_contribution_recur` (`contact_id`, `currency`, `frequency_unit`, `frequency_interval`,
            `start_date`, `contribution_status_id`, `payment_processor_id`, `financial_type_id`, `payment_instrument_id`,
            `cycle_day`, `auto_renew`, `amount`, `next_sched_contribution_date`, `create_date`)
            VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10, 1, %11, %12, NOW())";
    SQLQueryRunner::executeQuery($sqlQuery, $sqlParams);

    $dao = SQLQueryRunner::executeQuery('SELECT LAST_INSERT_ID() as recur_contribution_id');
    $dao->fetch();
    $recurContributionId = $dao->recur_contribution_id;

    $sqlQuery = "INSERT INTO `civicrm_value_contribution_recur_ext_id` (`entity_id` , `external_id`)
           VALUES (%1, %2)";
    SQLQueryRunner::executeQuery($sqlQuery, [
      1 => [$recurContributionId, 'Integer'],
      2 => [$this->rowData['recurring_contribution_external_id'], 'String'],
    ]);

    return $recurContributionId;
  }

  private function getRecurContributionIdIfExist() {
    $sqlQuery = "SELECT entity_id as id FROM civicrm_value_contribution_recur_ext_id WHERE external_id = %1";
    $recurContributionId = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['recurring_contribution_external_id'], 'String']]);
    $recurContributionId->fetch();

    if (!empty($recurContributionId->id)) {
      return $recurContributionId->id;
    }

    return NULL;
  }

  private function prepareSqlParams() {
    $currency = $this->getCurrency();
    $frequencyParams = $this->calculateFrequencyParameters();
    $recurContributionStatusId = $this->getInProgressStatusId();
    $paymentProcessorId = $this->getGoCardlessPaymentProcessorId();
    $financialTypeId = $this->getFinancialTypeId();
    $paymentMethodId = $this->getPaymentMethodId();
    $cycleDay = $this->getCycleDay($frequencyParams['unit']);
    $startDate = $this->formatDate('recurring_contribution_start_date', 'Start Date', TRUE);
    $amount = $this->getAmount();
    $nextSchedDate = $this->formatDate('recurring_contribution_next_sched_date', 'Next Scheduled Date', TRUE);

    return [
      1 => [$this->contactId, 'Integer'],
      2 => [$currency, 'String'],
      3 => [$frequencyParams['unit'], 'String'],
      4 => [$frequencyParams['interval'], 'Integer'],
      5 => [$startDate, 'String'],
      6 => [(int) $recurContributionStatusId, 'Integer'],
      7 => [(int) $paymentProcessorId, 'Integer'],
      8 => [(int) $financialTypeId, 'Integer'],
      9 => [(int) $paymentMethodId, 'Integer'],
      10 => [(int) $cycleDay, 'Integer'],
      11 => [$amount, 'Money'],
      12 => [$nextSchedDate, 'String'],
    ];
  }

  private function getCurrency() {
    $currencies = OptionValueCache::getCurrencies();

    if (!empty($currencies[strtolower($this->rowData['recurring_contribution_currency'])])) {
      return $currencies[strtolower($this->rowData['recurring_contribution_currency'])];
    }

    throw new InvalidFieldException('Invalid or disabled recurring contribution "currency"', 900);
  }

  private function calculateFrequencyParameters() {
    switch ($this->rowData['recurring_contribution_frequency_unit']) {
      case 'month':
        $frequencyParameters['unit'] = 'month';
        $frequencyParameters['interval'] = 1;
        break;

      case 'year':
        $frequencyParameters['unit'] = 'year';
        $frequencyParameters['interval'] = 1;
        break;

      case 'week':
        $frequencyParameters['unit'] = 'week';
        $frequencyParameters['interval'] = 1;
        break;

      default:
        throw new InvalidFieldException('Recurring Contribution Frequency Unit should be "month", "year", or "week"', 100);
    }

    return $frequencyParameters;
  }

  private function getInProgressStatusId() {
    $statuses = OptionValueCache::getRecurContributionStatuses();

    if (!empty($statuses['in progress'])) {
      return $statuses['in progress'];
    }

    throw new InvalidFieldException('Cannot find "In Progress" recurring contribution status', 200);
  }

  /**
   * Auto-detects the GoCardless payment processor.
   *
   * @return int
   *
   * @throws \CRM_Membershipextrasimporterapi_Exception_InvalidRecurringDonationFieldException
   */
  private function getGoCardlessPaymentProcessorId() {
    $processors = OptionValueCache::getPaymentProcessors();

    foreach ($processors as $processor) {
      if ($processor['class_name'] === 'Payment_GoCardless') {
        return $processor['id'];
      }
    }

    throw new InvalidFieldException('No active GoCardless payment processor found', 300);
  }

  private function getFinancialTypeId() {
    $financialTypeName = !empty($this->rowData['recurring_contribution_financial_type'])
      ? $this->rowData['recurring_contribution_financial_type']
      : 'Donation';

    $financialTypes = OptionValueCache::getFinancialTypes();

    if (!empty($financialTypes[$financialTypeName])) {
      return $financialTypes[$financialTypeName];
    }

    throw new InvalidFieldException('Invalid recurring contribution "Financial Type"', 400);
  }

  private function getPaymentMethodId() {
    $paymentMethodName = !empty($this->rowData['recurring_contribution_payment_instrument'])
      ? $this->rowData['recurring_contribution_payment_instrument']
      : 'direct_debit_gocardless';

    $paymentMethods = OptionValueCache::getPaymentMethods();

    if (!empty($paymentMethods[$paymentMethodName])) {
      return $paymentMethods[$paymentMethodName];
    }

    throw new InvalidFieldException('Invalid recurring contribution "Payment Instrument"', 500);
  }

  private function getCycleDay($frequencyUnit) {
    if ($frequencyUnit == 'year' || $frequencyUnit == 'week') {
      return 0;
    }

    if (empty($this->rowData['recurring_contribution_cycle_day'])) {
      throw new InvalidFieldException('Cycle day is required for monthly recurring contributions', 600);
    }

    $cycleDay = (int) $this->rowData['recurring_contribution_cycle_day'];

    if ($cycleDay < 1 || $cycleDay > 28) {
      throw new InvalidFieldException('Recurring Contribution Cycle day must be between 1 and 28', 700);
    }

    return $cycleDay;
  }

  /**
   * Formats a date from YYYY-MM-DD input format.
   *
   * @param string $dateColumnName
   *   The row data key.
   * @param string $columnLabel
   *   Human-readable label for error messages.
   * @param bool $isRequired
   *   Whether the date is required.
   *
   * @return string
   *   Formatted date string (Y-m-d).
   */
  private function formatDate($dateColumnName, $columnLabel, $isRequired = FALSE) {
    if ($isRequired && empty($this->rowData[$dateColumnName])) {
      throw new InvalidFieldException("Recurring Contribution '{$columnLabel}' is a required field.", 800);
    }

    if (!empty($this->rowData[$dateColumnName])) {
      $date = DateTime::createFromFormat('Y-m-d', $this->rowData[$dateColumnName]);
      if (!$date) {
        throw new InvalidFieldException("Recurring Contribution '{$columnLabel}' must be in YYYY-MM-DD format.", 850);
      }

      return $date->format('Y-m-d');
    }

    $date = new DateTime();
    return $date->format('Y-m-d');
  }

  private function getAmount() {
    if (empty($this->rowData['recurring_contribution_amount'])) {
      throw new InvalidFieldException('Recurring Contribution amount is a required field.', 1300);
    }

    $amount = $this->rowData['recurring_contribution_amount'];
    if (!is_numeric($amount) || $amount <= 0) {
      throw new InvalidFieldException('Recurring Contribution amount must be a positive number.', 1400);
    }

    return $amount;
  }

}
