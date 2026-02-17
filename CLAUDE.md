<!-- CLAUDE.md v1.5 | Last updated: 2026-02-17 -->

# Claude Code Instructions

> **Shared standards:** Read [.ai/shared-development-guide.md](.ai/shared-development-guide.md) for all coding standards, CI requirements, commit conventions, and best practices.
>
> **CiviCRM reference:** See [.ai/civicrm.md](.ai/civicrm.md) for CiviCRM core patterns and [.ai/extension.md](.ai/extension.md) for extension structure and testing.
>
> **Code review:** See [.ai/ai-code-review.md](.ai/ai-code-review.md) for review checklist and process.

This file contains **Claude Code-specific** instructions plus project-specific architecture context.

---

## Overview

This is a CiviCRM extension that provides the `MembershipextrasImporter` API endpoint for importing payment plan membership orders via CSV. It's designed to work with the CSV Importer extension (nz.co.fuzion.csvimport) and the Membershipextras extension (uk.co.compucorp.membershipextras).

## Commands

### Linting

```bash
# Install linting tools (first time setup)
bin/install-php-linter

# Run PHPCS
./phpcs.phar --standard=phpcs-ruleset.xml .

# Auto-fix with PHPCBF
./phpcbf.phar --standard=phpcs-ruleset.xml .
```

### Testing

Tests require a CiviCRM environment with the `cv` CLI tool available:

```bash
# Run all tests
phpunit --configuration phpunit.xml.dist

# Run a specific test file
phpunit --configuration phpunit.xml.dist tests/phpunit/CRM/Membershipextrasimporterapi/EntityImporter/ContributionTest.php

# Run a specific test method
phpunit --configuration phpunit.xml.dist --filter testMethodName
```

Tests extend `BaseHeadlessTest` which installs required extensions: membershipextras, manualdirectdebit, automateddirectdebit, and financeextras.

---

## Claude Code Workflow

### Plan Mode and Execution Mode

1. **Explain** -- Ask Claude to describe the issue in its own words
2. **Plan** -- Use Plan Mode (`Shift + Tab` twice) for complex tasks
3. **Review** -- Verify and edit the plan before implementation
4. **Implement** -- Disable Plan Mode and apply changes
5. **Verify** -- Run tests and linting

### Request Confirmation Before
- Deleting or overwriting files
- Database migrations
- Modifying auto-generated files (see shared guide, Section 10)

---

## Environment Constraints

- Cannot run tests or PHPStan directly without CiviCRM environment
- Can write test files following existing patterns
- Can fix errors based on CI output
- Suggest: "Push changes to trigger CI" or "Run tests locally with cv"

---

## Claude-Specific Rules

- **DO NOT add `Co-Authored-By: Claude` or any AI attribution** to commits
- Never push commits automatically without human review
- When proposing commits, use the `TICKET-###:` format from the shared guide
- Always read files before editing them

---

## Pre-Push Self-Review

Before proposing a commit, Claude can review its own changes in the same session:

```bash
# Replace <base-branch> with your PR target (master, main, develop, etc.)
git diff <base-branch>...HEAD
```

For unbiased review, use a **separate Claude session** and follow [.ai/ai-code-review.md](.ai/ai-code-review.md).

---

## Architecture

### Import Flow

The API endpoint `MembershipextrasImporter.create` is the entry point (`api/v3/MembershipextrasImporter.php`). Each CSV row triggers `CSVRowImporter::import()` which orchestrates entity creation in this order within a database transaction:

1. **RecurContribution** - Creates the payment plan (recurring contribution)
2. **Membership** - Creates membership linked to the payment plan
3. **Contribution** - Creates individual contribution record
4. **MembershipPayment** - Links membership to contribution
5. **LineItem** - Creates line items with financial records
6. **ManualDirectDebitMandate** - Creates DD mandate if using Manual DD extension
7. **ExternalDirectDebitMandate** - Creates external DD mandate if applicable

### Key Design Patterns

- **Entity Importers** (`CRM/Membershipextrasimporterapi/EntityImporter/`): Each entity type has its own importer class that handles creation and lookup by external ID
- **External ID Tracking**: Entities use external IDs stored in custom fields (e.g., `civicrm_value_contribution_ext_id`) to enable idempotent imports and updates
- **Raw SQL Performance**: Uses direct SQL via `SQLQueryRunner` instead of CiviCRM API for performance during bulk imports
- **Transaction Wrapping**: Each row import is wrapped in a transaction for atomicity

### Directory Structure

- `api/v3/` - CiviCRM API v3 endpoint definition with parameter specs
- `Civi/Api4/` - CiviCRM API v4 entity definition
- `CRM/Membershipextrasimporterapi/EntityImporter/` - Entity-specific import logic
- `CRM/Membershipextrasimporterapi/EntityCreator/` - Entity creation helpers
- `CRM/Membershipextrasimporterapi/Exception/` - Custom exceptions for validation errors

### Required Extensions

- `uk.co.compucorp.membershipextras` - Payment plan membership support
- `nz.co.fuzion.csvimport` - CSV import mechanism
- `uk.co.compucorp.manualdirectdebit` - Optional, for Direct Debit mandates
- `io.compuco.financeextras` - Optional, for multi-company accounting

---

## Developer Prompts (Examples)

| Task | Prompt |
|------|--------|
| Generate tests | "Create PHPUnit tests for `PaymentHandler::process()` covering success and error cases." |
| Summarize PR | "Summarize commits into PR description using template for TICKET-123." |
| Fix linting | "Fix PHPCS violations in `ContributionService.php`." |
| Review changes | "Review my changes against our coding standards: `git diff master...HEAD`" |
