<?php

declare(strict_types=1);

namespace App\Clients;

/**
 * Egy partnerből (+ opcionális szerződésből és a címezhető attribútumaiból) épít
 * egy lapos [kulcs => érték] térképet a dokumentum-kitöltéshez (TemplateFiller).
 *
 * A nevesített kulcsok (client_*, szerződésmezők) mellett minden client_attributes
 * sor a saját attr_key kulcsán is elérhető — így egy sablon hivatkozhat pl.
 * ${iban}, ${foglalkozas}, ${biztositott_2_nev} jelölőkre is. A nevesített
 * kulcsokat az attribútumok nem írják felül.
 */
final class ClientDataMap
{
    /**
     * @param array<string,mixed>        $client
     * @param array<string,mixed>|null   $contract
     * @param array<int,array<string,mixed>> $attributes  client_attributes sorok
     * @return array<string,string>
     */
    public function build(array $client, ?array $contract, array $attributes = []): array
    {
        $contract ??= [];

        $map = [
            // Partner
            'client_name' => (string) ($client['name'] ?? ''),
            'client_email' => (string) ($client['email'] ?? ''),
            'client_phone' => (string) ($client['mobile'] ?? $client['phone'] ?? ''),
            'client_mobile' => (string) ($client['mobile'] ?? ''),
            'client_address' => (string) ($client['address'] ?? ''),
            'tax_id' => (string) ($client['tax_id'] ?? ''),
            'birth_date' => (string) ($client['birth_date'] ?? ''),
            'birth_place' => (string) ($client['birth_place'] ?? ''),
            'mother_name' => (string) ($client['mother_name'] ?? ''),
            // Szerződés
            'category' => (string) ($contract['category'] ?? ''),
            'insurer_name' => (string) ($contract['insurer_name'] ?? ''),
            'module_code' => (string) ($contract['module_code'] ?? ''),
            'module_name' => (string) ($contract['module_name'] ?? ''),
            'policy_number' => (string) ($contract['policy_number'] ?? ''),
            'offer_number' => (string) ($contract['offer_number'] ?? ''),
            'start_date' => (string) ($contract['start_date'] ?? ''),
            'end_date' => (string) ($contract['end_date'] ?? ''),
            'anniversary' => (string) ($contract['anniversary'] ?? ''),
            'annual_fee' => (string) ($contract['annual_fee'] ?? ''),
            'payment_frequency' => (string) ($contract['payment_frequency'] ?? ''),
            'payment_method' => (string) ($contract['payment_method'] ?? ''),
            'agent_code' => (string) ($contract['agent_code'] ?? ''),
            'agent_name' => (string) ($contract['agent_name'] ?? ''),
            'risk_location' => (string) ($contract['risk_location'] ?? ''),
            'plate' => (string) ($contract['plate'] ?? ''),
            'today' => date('Y-m-d'),
        ];

        foreach ($attributes as $attr) {
            $key = trim((string) ($attr['attr_key'] ?? ''));
            // Üres kulcsot és a nevesített kulcsokkal ütközőt kihagyjuk.
            if ($key === '' || array_key_exists($key, $map)) {
                continue;
            }
            $map[$key] = (string) ($attr['value'] ?? '');
        }

        return $map;
    }
}
