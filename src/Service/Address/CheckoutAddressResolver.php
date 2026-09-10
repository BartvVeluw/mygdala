<?php

declare(strict_types=1);

namespace App\Service\Address;

/**
 * Turns whatever address fields a checkout form (shipping or billing —
 * api/checkout.php calls this twice, once per address) posted into the
 * address that actually gets stored on the order, applying Dutch BAG/PDOK
 * verification when the country is NL.
 *
 * For NL: never trusts the posted street/city at all. The postcode + house
 * number (+ addition) are looked up via DutchAddressLookupService, and the
 * returned street/city/postcode/house-number/addition — PDOK's own
 * canonical values — are what gets returned here, always. A request body of
 * "6511AA" + "12" + "Fake Street" + "Amsterdam" can never produce an order
 * with "Fake Street"/"Amsterdam": those two fields are simply discarded and
 * replaced. A postcode/house-number combination that PDOK can't confirm
 * throws AddressValidationException — the caller (api/checkout.php) must
 * never create the order/Mollie payment when that happens.
 *
 * For any other country: PDOK cannot help (Belgian/international addresses
 * aren't in BAG), so the posted street/city are used as-is — this method
 * assumes the caller already ran the normal required-field checks on them.
 */
class CheckoutAddressResolver
{
    public function __construct(private readonly DutchAddressLookupService $dutchLookup = new DutchAddressLookupService())
    {
    }

    /**
     * @return array{first_name:string, last_name:string, company:?string, country:string, postal_code:string, house_number:string, house_number_addition:?string, street:string, city:string}
     *
     * @throws AddressValidationException when country is NL and the address can't be verified (see class docblock)
     */
    public function resolve(
        string $firstName,
        string $lastName,
        ?string $company,
        string $country,
        string $postalCode,
        string $houseNumber,
        ?string $houseNumberAddition,
        string $street,
        string $city
    ): array {
        $country = strtoupper(trim($country));

        if ($country === 'NL') {
            $result = $this->dutchLookup->lookup($postalCode, $houseNumber, $houseNumberAddition);

            if (!$result->found) {
                throw AddressValidationException::notFound();
            }

            return [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company' => $company,
                'country' => 'NL',
                'postal_code' => (string) $result->postalCode,
                'house_number' => (string) $result->houseNumber,
                'house_number_addition' => $result->houseNumberAddition,
                'street' => (string) $result->street,
                'city' => (string) $result->city,
            ];
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $company,
            'country' => $country,
            'postal_code' => $postalCode,
            'house_number' => $houseNumber,
            'house_number_addition' => $houseNumberAddition,
            'street' => $street,
            'city' => $city,
        ];
    }
}
