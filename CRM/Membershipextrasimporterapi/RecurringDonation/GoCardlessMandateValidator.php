<?php

use CRM_Membershipextrasimporterapi_Exception_InvalidGoCardlessMandateException as InvalidMandateException;

/**
 * Validates GoCardless mandates via the GoCardless API.
 *
 * Uses a static cache to avoid redundant API calls when the same mandate
 * appears in multiple CSV rows within the same import batch.
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_GoCardlessMandateValidator {

  private static $validatedMandates = [];

  private static $mandateService = NULL;

  /**
   * Validates that a GoCardless mandate exists and is in an acceptable status.
   *
   * @param string $mandateId
   *   The GoCardless mandate ID (e.g., "MD000123").
   *
   * @return object
   *   The mandate object from GoCardless.
   *
   * @throws \CRM_Membershipextrasimporterapi_Exception_InvalidGoCardlessMandateException
   */
  public function validate($mandateId) {
    if (isset(self::$validatedMandates[$mandateId])) {
      return self::$validatedMandates[$mandateId];
    }

    try {
      $service = self::$mandateService ?? \Civi::service('service.gocardless.mandate');
      $mandate = $service->getMandateByID($mandateId);
    }
    catch (\Exception $e) {
      throw new InvalidMandateException('GoCardless mandate \'' . htmlspecialchars($mandateId, ENT_QUOTES, 'UTF-8') . '\' not found: ' . $e->getMessage(), 100);
    }

    $acceptableStatuses = ['active', 'pending_submission', 'submitted'];
    if (!in_array($mandate->status, $acceptableStatuses)) {
      throw new InvalidMandateException(
        'GoCardless mandate \'' . htmlspecialchars($mandateId, ENT_QUOTES, 'UTF-8') . '\' has status \'' . htmlspecialchars($mandate->status, ENT_QUOTES, 'UTF-8') . '\', expected one of: ' . implode(', ', $acceptableStatuses),
        200
      );
    }

    self::$validatedMandates[$mandateId] = $mandate;

    return $mandate;
  }

  /**
   * Sets a test mandate service to use instead of the container service.
   *
   * @param object $service
   *   A mock mandate service with a getMandateByID() method.
   */
  public static function setMandateService($service) {
    self::$mandateService = $service;
  }

  /**
   * Resets the static mandate cache and test service. Used for testing.
   */
  public static function reset() {
    self::$validatedMandates = [];
    self::$mandateService = NULL;
  }

}
