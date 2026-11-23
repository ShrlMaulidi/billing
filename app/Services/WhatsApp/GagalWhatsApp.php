<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** Permintaan ke WA gateway gagal; pesannya aman ditampilkan ke user (tanpa detail teknis/kunci). */
class GagalWhatsApp extends RuntimeException {}
