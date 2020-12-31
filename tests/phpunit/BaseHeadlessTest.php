<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

abstract class BaseHeadlessTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * Sets up Headless, use stock schema,, install extensions.
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->install('uk.co.compucorp.membershipextras')
      ->install('uk.co.compucorp.manualdirectdebit')
      ->installMe(__DIR__)
      ->apply();
  }

}
