#!/usr/bin/env python3
"""SSH: check DB content using drush.php directly."""
import paramiko

HOST = '83.220.168.96'
USER = 'vysotniki'
PASS = '6oLA6Ve8WC'
DRUPAL = '/home/www/vysotniki/vysotniki-servis.ru'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASS, timeout=15)

commands = [
    # Use drush.php directly
    f"cd {DRUPAL} && php vendor/bin/drush.php status 2>/dev/null | head -5",
    # Get config
    f"cd {DRUPAL} && php vendor/bin/drush.php config-get code_block_field.settings 2>/dev/null",
    # Check HTML in DB for paragraph 1343 (team page)
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | head -c 2000",
    # Check CSS in DB for background-image
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_css FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | grep -o 'background[^;]*url[^;]*' | head -5",
    # Check if data-cbf-asset is in the HTML for paragraph 1343
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | grep -o 'data-cbf-asset' | wc -l",
]

for cmd in commands:
    print(f"$ {cmd[:150]}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(out[:3000])
    if err and len(err) < 500:
        print(f"  STDERR: {err[:300]}")
    print()

client.close()
