<!-- .ai/unit-testing-guide.md v1.0 | Last updated: 2026-02-17 -->

# Unit Testing Guide

A practical guide for when to write tests, what to test, and how to write effective tests for CiviCRM extensions.

---

## 1. When to Write Tests

### Always Write Tests For

| Scenario | Why |
|----------|-----|
| **New features** | Proves the feature works as designed |
| **Bug fixes** | Prevents the bug from reoccurring |
| **Business logic** | Services, calculations, decision trees |
| **API endpoints** | Validates input/output contracts |
| **Data transformations** | Ensures data integrity |
| **Edge cases you discover** | Documents known gotchas |

### Test Coverage Priority

```
High Priority (Must Test)
├── Payment processing logic
├── Financial calculations
├── Webhook handlers
├── Data migrations / imports
├── Security-sensitive code
└── Complex business rules

Medium Priority (Should Test)
├── API wrappers
├── Form validation
├── Status transitions
└── Configuration handling

Lower Priority (Nice to Have)
├── Simple getters/setters
├── UI display logic
└── Logging
```

---

## 2. What Tests Should Cover

### The Three Cases Rule

Every test should cover at minimum:

1. **Positive case** — Happy path, expected input produces expected output
2. **Negative case** — Invalid input is handled gracefully (exceptions, error messages)
3. **Edge case** — Boundary conditions, empty values, nulls, limits

### Example: Testing a Payment Amount Validator

```php
class PaymentAmountValidatorTest extends BaseHeadlessTest {

  /**
   * Positive case: Valid amount is accepted.
   */
  public function testValidAmountIsAccepted(): void {
    $validator = new PaymentAmountValidator();

    $result = $validator->validate(100.00, 'GBP');

    $this->assertTrue($result->isValid());
  }

  /**
   * Negative case: Zero amount is rejected.
   */
  public function testZeroAmountIsRejected(): void {
    $validator = new PaymentAmountValidator();

    $result = $validator->validate(0, 'GBP');

    $this->assertFalse($result->isValid());
    $this->assertEquals('Amount must be greater than zero', $result->getError());
  }

  /**
   * Negative case: Negative amount is rejected.
   */
  public function testNegativeAmountIsRejected(): void {
    $validator = new PaymentAmountValidator();

    $result = $validator->validate(-50.00, 'GBP');

    $this->assertFalse($result->isValid());
  }

  /**
   * Edge case: Very small amount (minimum threshold).
   */
  public function testMinimumAmountThreshold(): void {
    $validator = new PaymentAmountValidator();

    // 0.01 is the minimum valid amount
    $result = $validator->validate(0.01, 'GBP');

    $this->assertTrue($result->isValid());
  }

  /**
   * Edge case: Amount with many decimal places is rounded.
   */
  public function testAmountIsRoundedToTwoDecimals(): void {
    $validator = new PaymentAmountValidator();

    $result = $validator->validate(99.999, 'GBP');

    $this->assertTrue($result->isValid());
    $this->assertEquals(100.00, $result->getNormalizedAmount());
  }
}
```

---

## 3. Test Structure: Arrange-Act-Assert

Every test should follow this pattern:

```php
public function testSomething(): void {
  // Arrange - Set up test data and dependencies
  $fabricator = new ContributionFabricator();
  $contribution = $fabricator->fabricate(['total_amount' => 100]);

  // Act - Execute the code under test
  $result = $this->service->processRefund($contribution['id'], 50);

  // Assert - Verify the expected outcome
  $this->assertTrue($result->isSuccess());
  $this->assertEquals(50, $result->getRefundedAmount());
}
```

### Good vs Bad Test Names

```php
// BAD - Vague, doesn't describe the scenario
public function testProcess(): void { }
public function testValidation(): void { }
public function test1(): void { }

// GOOD - Describes scenario and expected outcome
public function testProcessRefundCreatesFinancialTransaction(): void { }
public function testValidationFailsWhenAmountExceedsBalance(): void { }
public function testWebhookIsIgnoredWhenAlreadyProcessed(): void { }
```

---

## 4. Testing CiviCRM Extensions

### Base Test Class

Always extend `BaseHeadlessTest` for CiviCRM extension tests:

```php
namespace Civi\MyExtension\Test\Service;

use Civi\MyExtension\Test\BaseHeadlessTest;

class MyServiceTest extends BaseHeadlessTest {

  private MyService $service;

  protected function setUp(): void {
    parent::setUp();
    $this->service = new MyService();
  }
}
```

### Using Fabricators

Use fabricators to create test data consistently:

```php
// tests/phpunit/Fabricator/ContributionFabricator.php
class ContributionFabricator {

  /**
   * @phpstan-var array<string, string|int>
   */
  protected static array $defaultParams = [
    'financial_type_id' => 'Donation',
    'total_amount' => 100,
    'contribution_status_id:name' => 'Completed',
  ];

  /**
   * @param array $params Override default values
   * @return array The created contribution
   */
  public function fabricate(array $params = []): array {
    $result = \Civi\Api4\Contribution::create(FALSE)
      ->setValues(array_merge(self::$defaultParams, $params))
      ->execute()
      ->first();

    if (!is_array($result)) {
      throw new \RuntimeException('Failed to create test contribution');
    }

    return $result;
  }
}
```

### Testing API4 Results

Always guard against null/non-array results:

```php
public function testGetContributionReturnsExpectedData(): void {
  // Arrange
  $fabricator = new ContributionFabricator();
  $created = $fabricator->fabricate(['total_amount' => 250]);

  // Act
  $result = \Civi\Api4\Contribution::get(FALSE)
    ->addSelect('id', 'total_amount')
    ->addWhere('id', '=', $created['id'])
    ->execute()
    ->first();

  // Assert - Guard against null before accessing
  $this->assertNotNull($result);
  $this->assertIsArray($result);
  $this->assertEquals(250, $result['total_amount']);
}
```

---

## 5. Testing Webhooks and External APIs

### Mock External Services

Never call real external APIs in tests. Use mocks:

```php
class StripeWebhookHandlerTest extends BaseHeadlessTest {

  private StripeWebhookHandler $handler;
  private MockStripeClient $mockClient;

  protected function setUp(): void {
    parent::setUp();
    $this->mockClient = new MockStripeClient();
    $this->handler = new StripeWebhookHandler($this->mockClient);
  }

  public function testPaymentIntentSucceededUpdatesContribution(): void {
    // Arrange
    $contribution = $this->createPendingContribution();

    $webhookPayload = [
      'type' => 'payment_intent.succeeded',
      'data' => [
        'object' => [
          'id' => 'pi_test_123',
          'amount' => 10000, // £100.00 in pence
          'metadata' => ['contribution_id' => $contribution['id']],
        ],
      ],
    ];

    // Act
    $result = $this->handler->handle($webhookPayload);

    // Assert
    $this->assertEquals('applied', $result);

    $updated = $this->getContribution($contribution['id']);
    $this->assertEquals('Completed', $updated['contribution_status_id:name']);
  }

  public function testDuplicateWebhookIsIgnored(): void {
    // Arrange
    $contribution = $this->createCompletedContribution();

    $webhookPayload = [
      'type' => 'payment_intent.succeeded',
      'data' => [
        'object' => [
          'id' => 'pi_test_123',
          'metadata' => ['contribution_id' => $contribution['id']],
        ],
      ],
    ];

    // Act - Process same webhook twice
    $this->handler->handle($webhookPayload);
    $result = $this->handler->handle($webhookPayload);

    // Assert - Second call is a no-op
    $this->assertEquals('noop', $result);
  }
}
```

### Test Idempotency

Webhook handlers should be idempotent — processing the same event twice should not cause issues:

```php
public function testWebhookHandlerIsIdempotent(): void {
  $payload = $this->createTestWebhookPayload();

  // Process twice
  $result1 = $this->handler->handle($payload);
  $result2 = $this->handler->handle($payload);

  // First should apply, second should be noop
  $this->assertEquals('applied', $result1);
  $this->assertEquals('noop', $result2);

  // Verify only one contribution created
  $contributions = $this->getContributionsByExternalId($payload['id']);
  $this->assertCount(1, $contributions);
}
```

---

## 6. Testing Error Handling

### Test That Exceptions Are Thrown

```php
public function testInvalidCurrencyThrowsException(): void {
  $this->expectException(\CRM_Core_Exception::class);
  $this->expectExceptionMessage('Unsupported currency: XYZ');

  $this->service->processPayment(100, 'XYZ');
}
```

### Test Error Messages

When error messages are user-facing, test them explicitly:

```php
public function testValidationErrorMessageIsUserFriendly(): void {
  try {
    $this->service->processPayment(-100, 'GBP');
    $this->fail('Expected exception was not thrown');
  } catch (\CRM_Core_Exception $e) {
    // Assert message is user-friendly, not technical
    $this->assertStringNotContainsString('Exception', $e->getMessage());
    $this->assertStringNotContainsString('NULL', $e->getMessage());
    $this->assertStringContainsString('amount', strtolower($e->getMessage()));
  }
}
```

---

## 7. Test Data Cleanup

### Use Transactions (Preferred)

The `BaseHeadlessTest` should roll back database changes after each test:

```php
abstract class BaseHeadlessTest extends \PHPUnit\Framework\TestCase
  implements \Civi\Test\HeadlessInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  // Each test runs in a transaction that gets rolled back
}
```

### Manual Cleanup When Needed

If transactions don't work for your test, clean up manually:

```php
private array $createdContributionIds = [];

protected function tearDown(): void {
  foreach ($this->createdContributionIds as $id) {
    \Civi\Api4\Contribution::delete(FALSE)
      ->addWhere('id', '=', $id)
      ->execute();
  }
  parent::tearDown();
}
```

---

## 8. Anti-Patterns to Avoid

### Don't Test Implementation Details

```php
// BAD - Tests internal method call order
public function testProcessCallsValidateThenSave(): void {
  $mock = $this->createMock(Service::class);
  $mock->expects($this->exactly(1))->method('validate');
  $mock->expects($this->exactly(1))->method('save');
}

// GOOD - Tests observable behavior
public function testProcessSavesValidData(): void {
  $result = $this->service->process($validData);

  $this->assertTrue($result->isSaved());
  $this->assertDatabaseHas('civicrm_contribution', ['id' => $result->getId()]);
}
```

### Don't Weaken Tests to Make Them Pass

```php
// BAD - Removing assertion to "fix" failing test
public function testCalculateTotal(): void {
  $result = $this->service->calculateTotal([100, 200]);
  // $this->assertEquals(300, $result);  // Commented out because it fails
}

// GOOD - Fix the code, not the test
public function testCalculateTotal(): void {
  $result = $this->service->calculateTotal([100, 200]);
  $this->assertEquals(300, $result);
}
```

### Don't Use Sleep in Tests

```php
// BAD - Slow and unreliable
public function testAsyncProcess(): void {
  $this->service->startAsync();
  sleep(5); // Wait for completion
  $this->assertTrue($this->service->isComplete());
}

// GOOD - Use polling with timeout or mock the async behavior
public function testAsyncProcess(): void {
  $mockQueue = $this->createMock(QueueService::class);
  $mockQueue->expects($this->once())->method('enqueue');

  $this->service->startAsync();
  // Test that it was enqueued, not that it completed
}
```

---

## 9. Running Tests

```bash
# Run all tests
phpunit --configuration phpunit.xml.dist

# Run specific test file
phpunit --configuration phpunit.xml.dist tests/phpunit/Civi/MyExtension/Service/MyServiceTest.php

# Run specific test method
phpunit --configuration phpunit.xml.dist --filter testValidAmountIsAccepted
```

---

## 10. Checklist Before Committing

- [ ] All new code has corresponding tests
- [ ] Tests cover positive, negative, and edge cases
- [ ] Test names clearly describe the scenario
- [ ] No `sleep()` calls in tests
- [ ] No hardcoded IDs or dates that will break later
- [ ] External APIs are mocked, not called directly
- [ ] Tests run in isolation (no dependency on other tests)
- [ ] All tests pass locally: `phpunit --configuration phpunit.xml.dist`
