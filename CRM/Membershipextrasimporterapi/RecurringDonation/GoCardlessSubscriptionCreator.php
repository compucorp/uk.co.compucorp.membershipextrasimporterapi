<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;

/**
 * Creates a GoCardless subscription for a recurring contribution.
 *
 * After calling the GoCardless service, it verifies that the subscription
 * was actually created by checking processor_id on the recurring contribution,
 * since SubscriptionService::create() silently catches exceptions.
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_GoCardlessSubscriptionCreator {

  private static $subscriptionService = NULL;

  /**
   * Sets a test subscription service to use instead of the container service.
   *
   * @param object $service
   *   A mock subscription service with a create() method.
   */
  public static function setSubscriptionService($service) {
    self::$subscriptionService = $service;
  }

  /**
   * Resets the test subscription service. Used for testing.
   */
  public static function reset() {
    self::$subscriptionService = NULL;
  }

  /**
   * Creates a GoCardless subscription.
   *
   * @param int $recurContributionId
   *   The recurring contribution ID.
   * @param string $mandateId
   *   The GoCardless mandate ID.
   * @param string $description
   *   The subscription description.
   *
   * @return string
   *   The GoCardless subscription ID (processor_id).
   *
   * @throws \Exception
   */
  public function create($recurContributionId, $mandateId, $description) {
    $service = self::$subscriptionService ?? \Civi::service('service.gocardless.subscription');
    $service->create($recurContributionId, $mandateId, $description);

    $sqlQuery = "SELECT processor_id FROM civicrm_contribution_recur WHERE id = %1";
    $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$recurContributionId, 'Integer']]);
    $result->fetch();

    if (empty($result->processor_id)) {
      throw new \Exception(
        'GoCardless subscription creation failed silently for recurring contribution ID ' . (int) $recurContributionId . '. ' .
        'The processor_id was not set on the recurring contribution after the GoCardless API call.'
      );
    }

    return $result->processor_id;
  }

}
