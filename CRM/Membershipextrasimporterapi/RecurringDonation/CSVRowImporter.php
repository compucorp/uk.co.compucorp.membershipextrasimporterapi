<?php

use CRM_Membershipextrasimporterapi_Helper_SQLQueryRunner as SQLQueryRunner;
use CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_RecurContribution as RecurContributionImporter;
use CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_Contribution as ContributionImporter;
use CRM_Membershipextrasimporterapi_RecurringDonation_EntityImporter_LineItem as LineItemImporter;
use CRM_Membershipextrasimporterapi_RecurringDonation_GoCardlessMandateValidator as MandateValidator;
use CRM_Membershipextrasimporterapi_RecurringDonation_GoCardlessSubscriptionCreator as SubscriptionCreator;
use CRM_Membershipextrasimporterapi_Exception_InvalidContactException as InvalidContactException;

/**
 * Orchestrates the import of a single CSV row for recurring donations.
 *
 * Process flow (per spec):
 *   1. Validate GoCardless mandate
 *   2. Find/create contact
 *   3. Create recurring contribution (status: In Progress)
 *   4. Create first pending contribution + line items
 *   5. Create GoCardless subscription (stores processor_id on recur)
 *
 * Contact resolution order:
 *   1. contact_id → lookup by civicrm_contact.id
 *   2. contact_external_id → lookup by civicrm_contact.external_identifier
 *   3. email → lookup by civicrm_email.email (most recently modified contact)
 *   4. first_name + last_name + email, no match → create new Individual
 *   5. None of above → throw exception
 */
class CRM_Membershipextrasimporterapi_RecurringDonation_CSVRowImporter {

  private $rowData;

  private $contactId;

  public function __construct($rowData) {
    $this->rowData = $rowData;
    $this->contactId = $this->getContactId();
  }

  public function import() {
    $transaction = new CRM_Core_Transaction();
    try {
      $mandateValidator = new MandateValidator();
      $mandateValidator->validate($this->rowData['gocardless_mandate_id']);

      $recurContributionImporter = new RecurContributionImporter($this->rowData, $this->contactId);
      $recurContributionId = $recurContributionImporter->import();

      $contributionImporter = new ContributionImporter($this->rowData, $this->contactId, $recurContributionId);
      $contributionId = $contributionImporter->import();

      $lineItemImporter = new LineItemImporter($this->rowData, $contributionId);
      $lineItemImporter->import();

      $description = !empty($this->rowData['recurring_contribution_description'])
        ? $this->rowData['recurring_contribution_description']
        : 'Recurring Donation';
      $subscriptionCreator = new SubscriptionCreator();
      $subscriptionCreator->create($recurContributionId, $this->rowData['gocardless_mandate_id'], $description);

      $transaction->commit();
    }
    catch (Exception $e) {
      $transaction->rollback();
      throw $e;
    }
  }

  private function getContactId() {
    if (!empty($this->rowData['contact_id'])) {
      return $this->lookupByContactId();
    }

    if (!empty($this->rowData['contact_external_id'])) {
      return $this->lookupByExternalId();
    }

    if (!empty($this->rowData['email'])) {
      $contactId = $this->lookupByEmail();
      if ($contactId) {
        return $contactId;
      }

      if (!empty($this->rowData['first_name']) && !empty($this->rowData['last_name'])) {
        return $this->createNewContact();
      }

      throw new InvalidContactException('Contact not found by email and first_name + last_name are required to create a new contact.', 400);
    }

    throw new InvalidContactException('Either Contact Id, Contact External Id, or Email is required.', 300);
  }

  private function lookupByContactId() {
    $sqlQuery = "SELECT id FROM civicrm_contact WHERE id = %1 AND is_deleted = 0";
    $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['contact_id'], 'Integer']]);
    if (!$result->fetch()) {
      throw new InvalidContactException('Cannot find contact with Id = ' . (int) $this->rowData['contact_id'], 100);
    }

    return $result->id;
  }

  private function lookupByExternalId() {
    $sqlQuery = "SELECT id FROM civicrm_contact WHERE external_identifier = %1 AND is_deleted = 0";
    $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['contact_external_id'], 'String']]);
    if (!$result->fetch()) {
      throw new InvalidContactException('Cannot find contact with External Id = ' . htmlspecialchars($this->rowData['contact_external_id'], ENT_QUOTES, 'UTF-8'), 200);
    }

    return $result->id;
  }

  private function lookupByEmail() {
    $sqlQuery = "SELECT ce.contact_id as id FROM civicrm_email ce
                 INNER JOIN civicrm_contact cc ON ce.contact_id = cc.id
                 WHERE ce.email = %1 AND cc.is_deleted = 0
                 ORDER BY cc.modified_date DESC LIMIT 1";
    $result = SQLQueryRunner::executeQuery($sqlQuery, [1 => [$this->rowData['email'], 'String']]);

    if ($result->fetch()) {
      return $result->id;
    }

    return NULL;
  }

  private function createNewContact() {
    $contact = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', $this->rowData['first_name'])
      ->addValue('last_name', $this->rowData['last_name'])
      ->execute()
      ->first();

    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $contact['id'])
      ->addValue('email', $this->rowData['email'])
      ->addValue('is_primary', TRUE)
      ->execute();

    return $contact['id'];
  }

}
