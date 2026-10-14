<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Expenses;

use Illuminate\Foundation\Auth\User;

/**
 * The actor in the expense domain. Never stored: tests act as an in-memory employee.
 *
 * @property int $id
 * @property string $role manager, finance or staff
 */
class Employee extends User
{
    protected $guarded = [];

    public static function manager(int $id = 1): self
    {
        return new self(['id' => $id, 'role' => 'manager']);
    }

    public static function finance(int $id = 2): self
    {
        return new self(['id' => $id, 'role' => 'finance']);
    }

    public static function staff(int $id = 3): self
    {
        return new self(['id' => $id, 'role' => 'staff']);
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }
}
