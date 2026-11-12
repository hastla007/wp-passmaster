# AT / DE / HU profile scope (implementation baseline)

This is an implementation baseline, not legal advice or a final country approval. Applicability depends on the legal seller, activity, payment method, sales channel, location, and the exact register/product. An active profile must be reviewed by the operator's tax/compliance adviser and matched to a compatible provider adapter.

## Verified authority references

### Austria

- The Austrian business portal states that cash-register duties generally arise when **both** annual turnover exceeds EUR 15,000 and cash turnover exceeds EUR 7,500 for the business. Exceptions and effective dates must be evaluated for the particular business and revenue.
- Online-shop sales are exempt from the register requirement where customers do not pay the business directly in cash; receipt issuance and retention obligations still need to be considered.
- Since 1 October 2026, simplified electronic receipt delivery is possible, while a paper receipt can still be requested. Receipts must contain the required information and the business keeps a copy for seven years.
- The profile must distinguish POS/cash sales from non-cash online sales and support the applicable RKSV security device, receipt data, and export obligations.

Authority: [USP.gv.at — Registrierkassenpflicht](https://www.usp.gv.at/themen/steuern-finanzen/steuerliche-rechte-und-pflichten/registrierkassen.html), [USP.gv.at — Digitaler Beleg statt Kassenzettel](https://www.usp.gv.at/aktuelles/newsliste/digitaler-beleg-statt-kassenzettel.html).

### Germany

- The current KassenSichV defines covered electronic recording systems, including electronic or computer-based cash systems and cash registers, and lists exclusions. The plugin must not infer that every WooCommerce order is a cash-register transaction.
- The applicable process must be determined per sales channel and register. Where the sales operation is handled by an in-scope electronic recording system, the TSE workflow and receipt requirements must be implemented for the specific certified setup.
- The POS profile must capture the assigned register/TSE identity and support transaction start, completion, cancellation, receipt payload, and the applicable export/record-retention process. Online sales require a separate assessment.

Authorities: [KassenSichV, § 1 (official federal law)](https://ao.bundesfinanzministerium.de/ao/2026/Abgabenordnung/Vierter-Teil/Zweiter-Abschnitt/Erster-Unterabschnitt/Paragraf-146a/KassenSichV.html), [BMF FAQ — KassenSichV / Belegpflicht](https://www.bundesfinanzministerium.de/Content/DE/FAQ/FAQ-steuergerechtigkeit-belegpflicht.html).

### Hungary

- NAV states that from **1 September 2026**, data must be reported for manual and computer-generated receipts. The stated general window is within three calendar days, with daily aggregation by tax rate. The reporting data includes the receipt serial range and currency; corrections and exceptional cases need their own handling.
- Whether the seller must use an online cash register or an e-cash-register system depends on the activity and applicable rules. The software must support the applicable registered device/provider path; do not treat every Hungarian event as having the same requirement.
- NTAK is separate from NAV. For eligible tourist attractions, the NTAK requirements and approved ticketing software/reporting process must be assessed separately.

Authorities: [NAV — Receipt-data reporting](https://nav.gov.hu/ado/enyugta/nyugtaadat-szolgaltatas), [NAV — E-cash-register use and reporting](https://nav.gov.hu/ado/enyugta/kerdesek-es-valaszok/e-penztargep-hasznalat-adatszolgaltatas), [NTAK — Attractions](https://info.ntak.hu/attrakcio), [NTAK — FAQ](https://info.ntak.hu/gyik).

## Profile activation rules

The profile table supports seller, country, channel, location/register, environment, status, version, legal reference, review actor, and effective dates. Production activation requires the administrator to provide the reviewed rule-set reference/version and record an explicit review attestation. This records operator review; it does not certify the plugin.

Before a profile can be used for a mapped sale:

1. The seller is active and its legal name, VAT/tax number, and business address are present.
2. An active production profile exists for the seller and channel.
3. The profile country matches the seller country, its effective period includes the transaction, and its rule-set version/reference is recorded.
4. An enabled adapter exists and declares support for the profile country.
5. The provider has been tested and the seller/channel/register setup has passed acceptance review.

A missing profile or adapter must stop a mapped order before payment is accepted. It must not silently downgrade the sale to a non-fiscal transaction.

## Remaining country-specific decisions

- Confirm exceptions, receipts, retention, start/end/void behavior, and digital/paper output with a qualified adviser for the seller.
- Define the exact VAT/tax category mapping by product and country; do not rely solely on current ticket-type draft rates.
- Confirm whether provider output must be a separate receipt/invoice or may be included on the ticket.
- Confirm the applicable offline procedure and recovery window.
- Confirm activation/reporting, daily close, export, retention, and device registration tasks for each selected provider.
- For Hungary, document activity classification, NAV machine interface/provider certification path, receipt-series and daily batch reconciliation, and conditional NTAK eligibility separately.
