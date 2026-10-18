# Philippine delivery location reference

This directory contains public location names, PSGC codes and parent relationships
used by the buyer address book. The server reads the index and the selected
region's barangay file; the full reference is not included in the JavaScript
bundle. Creating or editing an address does not contact a third-party location
service.

The source is [fish-and-bear/psgc](https://github.com/fish-and-bear/psgc/tree/e360c9da26ae9455f357ab7d048389a1a8f05c2a),
pinned to commit `e360c9da26ae9455f357ab7d048389a1a8f05c2a`. That distribution
identifies its reference as the Philippine Statistics Authority's **first quarter
of 2026** PSGC. This is a pinned reference, not a claim to contain the newest
quarterly changes. The original classification and attribution belong to the
[Philippine Statistics Authority](https://psa.gov.ph/classification/psgc/).
The source package describes PSA data under CC BY 4.0 and distributes its code
under MIT; the package's MIT notice is retained in [LICENSE.txt](LICENSE.txt).

Only codes, names and relationships are retained. Coordinates, population,
unrelated metadata and network endpoints are omitted. The compiled files contain
18 regions, 85 delivery province/area groups, 1,642 city/municipality selections
and 42,010 barangays. Delivery groups are not a count of official provinces.

For delivery selection, NCR is grouped as **Metro Manila**. Independent-city
groups are associated with a geographic province only when the source's old
correspondence codes identify that province within the same current region;
otherwise they remain separate areas. Manila's submunicipalities are combined
under **City of Manila**, retaining every source barangay. Region assignments
are preserved, including the Negros Island Region.

`PhilippineAddressCatalog` validates the same hierarchy that supplies the lists.
It accepts unique, case-equivalent location names and common city-prefix/suffix
aliases, then saves the reference's canonical spelling. It does not guess an
ambiguous parent. Geographic validity does not establish delivery serviceability:
checkout still checks the configured hub and road network.

Before replacing this snapshot, review source attribution, region changes,
parent mappings, legacy saved names and the catalogue integrity tests. Keep
existing order and parcel snapshots unchanged.
