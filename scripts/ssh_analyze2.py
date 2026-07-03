#!/usr/bin/env python3
"""SSH into the server and analyze the code_block_field module."""
import paramiko

HOST = '83.220.168.96'
USER = 'vysotniki'
PASS = '6oLA6Ve8WC'
DRUPAL = '/home/www/vysotniki/vysotniki-servis.ru'
MODULE = f'{DRUPAL}/web/modules/custom/code_block_field'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASS, timeout=15)
print("=== Connected ===\n")

commands = [
    # Module version
    f"cat {MODULE}/code_block_field.info.yml | grep version",
    # Library versions (the cache-busting fix)
    f"grep 'version:' {MODULE}/code_block_field.libraries.yml",
    # Check if 1.4.7 try-catch fix is in the JS
    f"grep -c 'CBF enableEditing' {MODULE}/js/inline-editor.js",
    # Check if single querySelectorAll('img') fix is in place
    f"grep -c \"querySelectorAll('img')\" {MODULE}/js/inline-editor.js",
    # Check bg-image selector (should be comma CSS selector, not JS comma)
    f"grep 'background-image.*background:' {MODULE}/js/inline-editor.js | head -2",
    # Check if disableEditing resets link flag
    f"grep -c 'cbfLinkHandleAttached' {MODULE}/js/inline-editor.js",
    # Check if auto_assign_asset_keys is enabled in config
    f"cd {DRUPAL} && php vendor/bin/drush config-get code_block_field.settings 2>/dev/null | grep -E 'auto_assign|filter_html'",
    # Check if ProcessImages is called in hook_entity_presave
    f"grep -c 'ProcessImages::process' {MODULE}/code_block_field.module",
    # Check the actual data-cbf-asset on images in the DB
    f"cd {DRUPAL} && php vendor/bin/drush sql:query \"SELECT field_code_block_html FROM paragraph__field_code_block WHERE entity_id=1337\" 2>/dev/null | head -c 500",
    # Check watchdog for recent code_block_field entries
    f"cd {DRUPAL} && php vendor/bin/drush watchdog:list --type=code_block_field 2>/dev/null | tail -10",
    # Check file permissions on JS
    f"ls -la {MODULE}/js/inline-editor.js",
    # Check the first 3 lines of inline-editor.js to verify it's ours
    f"head -3 {MODULE}/js/inline-editor.js",
    # Check if there are any PHP errors in the log
    f"tail -5 /var/log/php-fpm/www.log 2>/dev/null || tail -5 /var/log/php/error.log 2>/dev/null || echo 'no php log found'",
]

for cmd in commands:
    print(f"$ {cmd[:120]}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(out[:2000])
    if err and len(err) < 500:
        print(f"  STDERR: {err}")
    print()

client.close()
