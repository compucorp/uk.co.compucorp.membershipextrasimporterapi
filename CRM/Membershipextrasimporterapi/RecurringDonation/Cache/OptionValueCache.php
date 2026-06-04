<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;

/**
 * Static singleton cache for option values used during recurring donation import.
 *
 * Since the CSV import extension processes all rows in a single PHP request,
 * static properties persist across rows, avoiding redundant DB queries.
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_Cache_OptionValueCache {

  private static $currencies;

  private static $financialTypes;

  private static $paymentMethods;

  private static $contributionStatuses;

  private static $recurContributionStatuses;

  private static $paymentProcessors;

  /**
   * Returns enabled currencies keyed by lowercase value.
   *
   * @return array
   *   [lowercase_value => id]
   */
  public static function getCurrencies() {
    if (self::$currencies === NULL) {
      self::$currencies = [];
      $sqlQuery = "SELECT cov.name as name, LOWER(cov.value) as value, cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'currencies_enabled'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        self::$currencies[$result->value] = $result->id;
      }
    }

    return self::$currencies;
  }

  /**
   * Returns financial types keyed by name.
   *
   * @return array
   *   [name => id]
   */
  public static function getFinancialTypes() {
    if (self::$financialTypes === NULL) {
      self::$financialTypes = [];
      $sqlQuery = "SELECT id, name FROM civicrm_financial_type";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        self::$financialTypes[$result->name] = $result->id;
      }
    }

    return self::$financialTypes;
  }

  /**
   * Returns payment methods keyed by name.
   *
   * @return array
   *   [name => id]
   */
  public static function getPaymentMethods() {
    if (self::$paymentMethods === NULL) {
      self::$paymentMethods = [];
      $sqlQuery = "SELECT cov.name as name, cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'payment_instrument'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        self::$paymentMethods[$result->name] = $result->id;
      }
    }

    return self::$paymentMethods;
  }

  /**
   * Returns contribution statuses keyed by lowercase name.
   *
   * @return array
   *   [lowercase_name => id]
   */
  public static function getContributionStatuses() {
    if (self::$contributionStatuses === NULL) {
      self::$contributionStatuses = [];
      $sqlQuery = "SELECT LOWER(cov.name) as name, cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'contribution_status'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        self::$contributionStatuses[$result->name] = $result->id;
      }
    }

    return self::$contributionStatuses;
  }

  /**
   * Returns recurring contribution statuses keyed by lowercase name.
   *
   * @return array
   *   [lowercase_name => id]
   */
  public static function getRecurContributionStatuses() {
    if (self::$recurContributionStatuses === NULL) {
      self::$recurContributionStatuses = [];
      $sqlQuery = "SELECT LOWER(cov.name) as name, cov.value as id FROM civicrm_option_value cov
                  INNER JOIN civicrm_option_group cog ON cov.option_group_id = cog.id
                  WHERE cog.name = 'contribution_recur_status'";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        self::$recurContributionStatuses[$result->name] = $result->id;
      }
    }

    return self::$recurContributionStatuses;
  }

  /**
   * Returns active payment processors keyed by name.
   *
   * @return array
   *   [name => ['id' => int, 'class_name' => string]]
   */
  public static function getPaymentProcessors() {
    if (self::$paymentProcessors === NULL) {
      self::$paymentProcessors = [];
      $sqlQuery = "SELECT id, name, class_name FROM civicrm_payment_processor WHERE is_test = 0 AND is_active = 1";
      $result = SQLQueryRunner::executeQuery($sqlQuery);
      while ($result->fetch()) {
        self::$paymentProcessors[$result->name] = ['id' => $result->id, 'class_name' => $result->class_name];
      }
    }

    return self::$paymentProcessors;
  }

  /**
   * Resets all caches. Used for testing.
   */
  public static function reset() {
    self::$currencies = NULL;
    self::$financialTypes = NULL;
    self::$paymentMethods = NULL;
    self::$contributionStatuses = NULL;
    self::$recurContributionStatuses = NULL;
    self::$paymentProcessors = NULL;
  }

}
