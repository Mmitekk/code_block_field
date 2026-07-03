#!/usr/bin/env python3
"""SSH into the server and analyze the code_block_field module."""
import paramiko
import sys

HOST = '83.220.168.96'
USER = 'vysotniki'
PASS = '6oLA6Ve8WC'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())

try:
    client.connect(HOST, username=USER, password=PASS, timeout=15)
    print("=== Connected ===\n")
except Exception as e:
    print(f"Connection failed: {e}")
    sys.exit(1)

commands = [
    # Find Drupal root
    "find /var/www -maxdepth 4 -name 'code_block_field.info.yml' 2>/dev/null | head -5",
    # Check module version
    "cat /var/www/vysotniki-servis.ru/web/modules/custom/code_block_field/code_block_field.info.yml 2>/dev/null | grep version",
    # Check the actual JS file for the try-catch fix (1.4.7)
    "grep -c 'CBF enableEditing' /var/www/vysotniki-servis.ru/web/modules/custom/code_block_field/js/inline-editor.js 2>/dev/null",
    # Check if the image selector fix is in place (1.4.6 — single querySelectorAll('img'))
    "grep -c \"querySelectorAll.'img'\" /var/www/vysotniki-servis.ru/web/modules/custom/code_block_field/js/inline-editor.js 2>/dev/null",
    # Check if bg-image selector is the fixed one (comma selector, not comma operator)
    "grep 'background-image.*background:' /var/www/vysotniki-servis.ru/web/modules/custom/code_block_field/js/inline-editor.js 2>/dev/null | head -2",
    # Check the actual library version in libraries.yml
    "grep 'version:' /var/www/vysotniki-servis.ru/web/modules/custom/code_block_field/code_block_field.libraries.yml 2>/dev/null",
    # Check config
    "cd /var/www/vysotniki-servis.ru && php vendor/bin/drush config-get code_block_field.settings 2>/dev/null | head -20",
    # Check if ProcessImages is called in hook_entity_presave
    "grep -c 'ProcessImages::process' /var/www/vysotniki-servis.ru/web/modules/custom/code_block_field/code_block_field.module 2>/dev/null",
    # Check the last 30 lines of inline-editor.js to verify it's the latest
    "tail -5 /var/www/vysotniki-servis.ru/web/modules/custom/code_block_field/js/inline-editor.js 2>/dev/null",
]

for cmd in commands:
    print(f"$ {cmd}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(out)
    if err:
        print(f"  STDERR: {err}")
    print()

client.close()
