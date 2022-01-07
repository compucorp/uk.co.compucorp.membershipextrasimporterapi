# Membershipextras Importer API

This extension creates new API Endpoint `MembershipextrasImporter` that can be used within [CSV Importer](https://github.com/eileenmcnaughton/nz.co.fuzion.csvimport) extension,
which allows importing Payment Plan membership orders and direct debit information using the data model used in [Membershipextras](https://github.com/compucorp/uk.co.compucorp.membershipextras) suite.

More details about the functionality of this importer and fields mapping are available here (not publicly available document yet):
https://compucorp.atlassian.net/wiki/spaces/ME/pages/2307489795/Membership+importer+Ready+for+kickoff+Payment+plan+importer

# Dependencies
To be able to use this extension you will need :

- [Membershipextras extension](https://github.com/compucorp/uk.co.compucorp.membershipextras) : Which provides support for payment plan memberships.
- [CSV Importer extension](https://github.com/eileenmcnaughton/nz.co.fuzion.csvimport) : Which provides the mechanism to import CSV files using any API Endpoint.

And optionally :
- [Manual Direct debit extension](https://github.com/compucorp/uk.co.compucorp.manualdirectdebit) : In case you have payment plan orders paid with Direct debit.

## Usage

As mentioned above, this extension is to be used within  [CSV Importer](https://github.com/eileenmcnaughton/nz.co.fuzion.csvimport) extension, by going
to the extension import screen and selecting `MembershipextrasImporter` in "Entity To Import" select list:

![1](https://user-images.githubusercontent.com/6275540/115318172-e0d96d00-a185-11eb-9350-704a0f2370c6.png)

Then choose the CSV file you want to import and go through the rest of the steps. Though, hence the following:

1- Once the import begins, it will process rows in batches and it will trigger separate Ajax request for each batch, the number of rows to be processed
in each batch are controlled by "Number Of Items To Process For Each Queue Item" setting :

![2](https://user-images.githubusercontent.com/6275540/115318178-e3d45d80-a185-11eb-909e-5e8a425dbdd8.png)

You need to choose a number that is not very large which might result in timeout issues, nor a number that is very small that result in a lot of Ajax
requests to the server. For example, suppose you have 100,000 records to import and suppose that your PHP timeout setting is 60 seconds, if you select
the number of items to process setting to be 1, then the importer will trigger 100,000 ajax request to the server which is a lot, and if you choose a number
such as 10,000, then the importer will most likely take more than 60 seconds to process such number of rows in a single Ajax request, so try to find a number
that works best for your server configurations.

2- Both "Allow Updating An Entity Using Unique Fields" and "Ignore Case For Field Option Values" do not work currently, and enabling them will prevent the importer
from running and might cause issues. So just keep them disabled. Although hence that `MembershipextrasImporter` API will by default try
to update existing records, also hence that it by default looks for existing records using the supplied external id for each entity.

![3](https://user-images.githubusercontent.com/6275540/115318186-eb940200-a185-11eb-89a3-8766f4125e70.png)

3- Depending on your server configurations and the size of the data you are trying to import,you might need to consider increasing
the following configurations:

A- Nginx `client_max_body_size`.
B- PHP `upload_max_filesize` and `post_max_size`.
C- CiviCRM `Maximum File Size` which is available at `/civicrm/admin/setting/misc?reset=1` and should match PHP `upload_max_filesize`.

also consider splitting large files into smaller ones and import them one by one.
