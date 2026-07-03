#!/usr/bin/env python3
"""SSH: check DB content and drush."""
import paramiko

HOST = '83.220.168.96'
USER = 'vysotniki'
PASS = '6oLA6Ve8WC'
DRUPAL = '/home/www/vysotniki/vysotniki-servis.ru'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASS, timeout=15)

commands = [
    # Find drush
    "which drush 2>/dev/null",
    "find /home/www/vysotniki -maxdepth 3 -name drush -type f 2>/dev/null",
    "ls -la /home/www/vysotniki/vysotniki-servis.ru/vendor/bin/ 2>/dev/null | head -10",
    # Try drush via php
    f"cd {DRUPAL} && php vendor/drush/drush/drush status 2>/dev/null | head -10",
    # Check config via drush
    f"cd {DRUPAL} && php vendor/drush/drush/drush config-get code_block_field.settings 2>/dev/null",
    # Check the HTML in DB for paragraph 1337
    f"cd {DRUPAL} && php vendor/drush/drush/drush sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1337' 2>/dev/null | head -c 1000",
    # Check the HTML in DB for paragraph 1343 (team page)
    f"cd {DRUPAL} && php vendor/drush/drush/drush sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | head -c 1000",
    # Check if data-cbf-asset is present in DB
    f"cd {DRUPAL} && php vendor/drush/drush/drush sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | grep -o 'data-cbf-asset' | wc -l",
    # Check CSS in DB for background-image
    f"cd {DRUPAL} && php vendor/drush/drush/drush sql:query 'SELECT field_code_block_css FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | grep -o 'background.*url' | head -5",
    # Check watchdog recent
    f"cd {DRUPAL} && php vendor/drush/drush/drush watchdog:list --type=code_block_field 2>/dev/null | tail -5",
]

for cmd in commands:
    print(f"$ {cmd[:150]}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(out[:2000])
    if err and len(err) < 500:
        print(f"  STDERR: {err[:300]}")
    print()

client.close()
