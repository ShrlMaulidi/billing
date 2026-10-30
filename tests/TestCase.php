<?php

namespace Tests;

use Database\Seeders\HakAksesSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Test ber-RefreshDatabase selalu punya daftar role & permission bawaan.
     * Isi false di test yang perlu database benar-benar kosong.
     *
     * @var class-string|false
     */
    protected $seeder = HakAksesSeeder::class;
}
