<?php

namespace App\Repository;

use App\Database;
use PDO;

/**
 * Base class for table-specific repositories (e.g. ProductRepository, OrderRepository).
 * Keeps raw SQL confined to repository classes instead of scattered through the app.
 * Every class in this directory extends it:
 *
 *   class ProductRepository extends Repository
 *   {
 *       public function findBySlug(string $slug): ?array { ... }
 *   }
 *
 * The constructor takes an optional PDO, so a caller can hand in an existing
 * connection (a transaction, a test); left out, it uses the shared one.
 */
abstract class Repository
{
    protected PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }
}
