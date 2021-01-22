<?php

use CRM_MembershipExtras_Test_Fabricator_Contact as ContactFabricator;
use CRM_MembershipExtras_Test_Fabricator_MembershipType as MembershipTypeFabricator;
use CRM_MembershipExtras_Test_Fabricator_Membership as MembershipFabricator;
use CRM_MembershipExtras_Test_Fabricator_Contribution as ContributionFabricator;
use CRM_Membershipextrasimporterapi_EntityCreator_MembershipPayment as MembershipPaymentCreator;

/**
 *
 * @group headless
 */
class CRM_Membershipextrasimporterapi_EntityCreator_MembershipPaymentTest extends BaseHeadlessTest {

  private $contactId;

  private $membershipId;

  private $contributionId;

  public function setUp() {
    $this->contactId = ContactFabricator::fabricate()['id'];

    MembershipTypeFabricator::fabricate(['name' => 'Student', 'minimum_fee' => 50]);
    $this->membershipId = MembershipFabricator::fabricate([
      'contact_id' => $this->contactId,
      'membership_type_id' => 'Student',
      'join_date' => date('Y-m-d', strtotime('-1 year')),
      'start_date' => date('Y-m-d', strtotime('-1 year')),
    ])['id'];

    $this->contributionId = ContributionFabricator::fabricate([
      'contact_id' => $this->contactId,
      'financial_type_id' => 'Member Dues',
      'total_amount' => 50,
    ])['id'];
  }

  public function testCreate() {
    $membershipPaymentCreator = new MembershipPaymentCreator($this->membershipId, $this->contributionId);
    $createResponse = $membershipPaymentCreator->create();

    $this->assertTrue($createResponse);
  }

  public function testCreateWillNotDuplicateRecords() {
    $membershipPaymentCreator = new MembershipPaymentCreator($this->membershipId, $this->contributionId);
    $createResponse = $membershipPaymentCreator->create();
    $this->assertTrue($createResponse);

    $membershipPaymentCreator = new MembershipPaymentCreator($this->membershipId, $this->contributionId);
    $createResponse = $membershipPaymentCreator->create();
    $this->assertFalse($createResponse);
  }

}
