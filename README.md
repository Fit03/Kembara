# Kembara

Sistem tempahan kenderaan PHP 8 + PDO untuk Perbendaharaan Negeri Selangor.

## Cron

Salin `config/mail.example.php` kepada `config/mail.php` dan isi tetapan SMTP. Fail `config/mail.php` tidak boleh dimasukkan ke Git.

Jalankan setiap 5 minit untuk menghantar e-mel berstatus `Pending`:

```text
*/5 * * * * /usr/bin/php /path/to/vehicle-booking/cron/send_emails.php >> /var/log/kembara-send-emails.log 2>&1
```

Jalankan setiap 15 minit untuk peringatan perjalanan dan tugasan:

```text
*/15 * * * * /usr/bin/php /path/to/vehicle-booking/cron/reminders.php >> /var/log/kembara-reminders.log 2>&1
```

Kedua-dua skrip menolak akses web dan hanya boleh dijalankan melalui CLI.
