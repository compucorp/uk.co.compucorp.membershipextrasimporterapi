<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

abstract class RecurringDonationBaseHeadlessTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * Sets up Headless, use stock schema, install extensions including GoCardless.
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('uk.co.compucorp.membershipextras')
      ->install('uk.co.compucorp.manualdirectdebit')
      ->install('io.compuco.automateddirectdebit')
      ->install('io.compuco.financeextras')
      ->install('io.compuco.gocardless')
      ->installMe(__DIR__)
      ->apply();
  }

}
