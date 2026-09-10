<?php

namespace App\Repository;

/**
 * All customer-related SQL lives here. No customer accounts/login — this is
 * just the record of who an order belongs to, keyed by email.
 */
class CustomerRepository extends Repository
{
    /**
     * Creates a customer, or updates and reuses the existing one for this
     * email address (repeat checkout with the same email refreshes the
     * stored contact/address details instead of creating a duplicate row).
     *
     * @param array<string, mixed> $data name, email, phone, address_line, postal_code, city, country
     */
    public function findOrCreateByEmail(array $data): int
    {
        $stmt = $this->db->prepare('SELECT id FROM customers WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $data['email']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $update = $this->db->prepare(
                'UPDATE customers
                 SET name = :name, phone = :phone, address_line = :address_line,
                     postal_code = :postal_code, city = :city, country = :country,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'name' => $data['name'],
                'phone' => $data['phone'],
                'address_line' => $data['address_line'],
                'postal_code' => $data['postal_code'],
                'city' => $data['city'],
                'country' => $data['country'],
                'id' => $existing['id'],
            ]);

            return (int) $existing['id'];
        }

        $insert = $this->db->prepare(
            'INSERT INTO customers (name, email, phone, address_line, postal_code, city, country, created_at, updated_at)
             VALUES (:name, :email, :phone, :address_line, :postal_code, :city, :country, NOW(), NOW())'
        );
        $insert->execute([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address_line' => $data['address_line'],
            'postal_code' => $data['postal_code'],
            'city' => $data['city'],
            'country' => $data['country'],
        ]);

        return (int) $this->db->lastInsertId();
    }
}
