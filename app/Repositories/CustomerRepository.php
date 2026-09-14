<?php

namespace App\Repositories;

use App\Models\Customer;

class CustomerRepository
{
    /**
     * Find the customer with this email, or create them.
     *
     * Do not reach for firstOrCreate here. It is a read followed by a write,
     * which is the same read-modify-write race the seat allocator exists to
     * avoid: two concurrent bookings from the same person would both find
     * nothing, both insert, and one would die on the unique email index.
     *
     * upsert() compiles to a single "INSERT ... ON CONFLICT (email) DO UPDATE"
     * statement, which cannot interleave. The follow-up read costs one cheap
     * indexed lookup and buys us portability over hand-written SQL.
     */
    public function findOrCreate(string $name, string $email, ?string $phone): int
    {
        Customer::upsert(
            values: [['name' => $name, 'email' => $email, 'phone' => $phone]],
            uniqueBy: ['email'],
            // Only overwrite the phone when this booking actually supplied one,
            // so an omitted number never erases one we already had.
            update: $phone === null ? ['name'] : ['name', 'phone'],
        );

        return (int) Customer::where('email', $email)->value('id');
    }
}
