#!/usr/bin/env python3
"""SSH: test the image upload endpoint directly."""
import paramiko
import io

HOST = '83.220.168.96'
USER = 'vysotniki'
PASS = '6oLA6Ve8WC'
DRUPAL = '/home/www/vysotniki/vysotniki-servis.ru'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASS, timeout=15)
print("=== Connected ===\n")

commands = [
    # Test the upload endpoint with curl to see what it returns
    f"cd {DRUPAL} && curl -s -o /dev/null -w '%{{http_code}}' -X POST 'https://vysotniki-servis.ru/admin/code-block-field/image-upload' -H 'Cookie: SSESSxxx=yyy' 2>/dev/null",
    # Check the route definition
    f"grep -A5 'image_upload' {DRUPAL}/web/modules/custom/code_block_field/code_block_field.routing.yml",
    # Check if the route requires CSRF token
    f"grep -B2 -A8 'image.upload' {DRUPAL}/web/modules/custom/code_block_field/code_block_field.routing.yml",
    # Check PHP error log
    f"tail -20 /var/log/php-fpm/www.log 2>/dev/null || tail -20 /var/log/php/error.log 2>/dev/null || echo 'no php log'",
    # Check if there's a Drupal watchdog error
    f"cd {DRUPAL} && php vendor/bin/drush.php watchdog:list --type=php 2>/dev/null | tail -10",
    # Check Apache error log
    f"tail -10 /var/log/httpd/vysotniki-servis.ru_error_log 2>/dev/null || tail -10 /var/log/httpd/error_log 2>/dev/null || echo 'no apache log'",
    # Check if the endpoint is accessible (might be behind SSL)
    f"curl -sk -X POST 'https://vysotniki-servis.ru/admin/code-block-field/image-upload' -H 'Content-Type: multipart/form-data' 2>&1 | head -20",
]

for cmd in commands:
    print(f"$ {cmd[:150]}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(out[:2000])
    if err and len(err) < 500:
        print(f"  STDERR: {err}")
    print()

client.close()
