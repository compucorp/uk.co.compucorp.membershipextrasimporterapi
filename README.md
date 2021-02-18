# Membershipextras Importer API

This extension creates new API Endpoint `MembershipextrasImporter`, that can be used within [CSV Importer](https://github.com/eileenmcnaughton/nz.co.fuzion.csvimport) extension,
and allows importing Payment Plan membership orders and direct debit information for the use of Membershipextras suite.

# Dependencies
To be able to use this extension you will need : 

- [Membershipextras extension](https://github.com/compucorp/uk.co.compucorp.membershipextras) : Which provides support for payment plan memberships
- [CSV Importer extension](https://github.com/eileenmcnaughton/nz.co.fuzion.csvimport) : Which provides the mechanism to import CSV files using any API Endpoint

And optionally : 
- [Manual Direct debit extension](https://github.com/compucorp/uk.co.compucorp.manualdirectdebit) : In case you have payment plan orders paid with Direct debit.


It also requires the following custom groups and their custom fields to be activated : 

- Recurring Contribution External ID
- Contribution External ID
- Membership External ID
