#!/usr/bin/env python3
"""SSH: compare HTML between /stranica and /team paragraphs."""
import paramiko

HOST = '83.220.168.96'
USER = 'vysotniki'
PASS = '6oLA6Ve8WC'
DRUPAL = '/home/www/vysotniki/vysotniki-servis.ru'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASS, timeout=15)

commands = [
    # Find ALL paragraphs with code_block field
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT entity_id, LEFT(field_code_block_html, 200) as html_start FROM paragraph__field_code_block ORDER BY entity_id' 2>/dev/null",
    # Check /stranica paragraph (1337) for data-cbf-overlay-attached
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1337' 2>/dev/null | grep -o 'data-cbf-overlay-attached' | wc -l",
    # Check /team paragraph (1343) for data-cbf-overlay-attached
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | grep -o 'data-cbf-overlay-attached' | wc -l",
    # Check for data-cbf-link-handle-attached in both
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1337' 2>/dev/null | grep -o 'data-cbf-link-handle-attached' | wc -l",
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | grep -o 'data-cbf-link-handle-attached' | wc -l",
    # Show full HTML for 1343 to see what attributes are there
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | head -c 3000",
    # Check the JS file version
    f"grep 'version:' /home/www/vysotniki/vysotniki-servis.ru/web/modules/custom/code_block_field/code_block_field.libraries.yml",
    # Check if collectPayload has the fix
    f"grep -c 'data-cbf-overlay-attached' /home/www/vysotniki/vysotniki-servis.ru/web/modules/custom/code_block_field/js/inline-editor.js",
    # Check the CSS for bg-image editing
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT field_code_block_css FROM paragraph__field_code_block WHERE entity_id=1343' 2>/dev/null | grep -o 'background[^;]*url[^;]*' | head -5",
]

for cmd in commands:
    print(f"$ {cmd[:150]}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(out[:3000])
    if err and len(err) < 300:
        print(f"  STDERR: {err}")
    print()

client.close()
