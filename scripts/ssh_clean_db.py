#!/usr/bin/env python3
"""SSH: clean stale data-cbf-* attributes from the database."""
import paramiko

HOST = '83.220.168.96'
USER = 'vysotniki'
PASS = '6oLA6Ve8WC'
DRUPAL = '/home/www/vysotniki/vysotniki-servis.ru'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASS, timeout=15)
print("=== Connected ===\n")

# Clean ALL stale data-cbf-* attributes from the database.
# These were saved by old versions of the module (before 1.4.9) and
# cause the inline editor to skip images/links on first click.
sql_commands = [
    # Remove data-cbf-overlay-attached="1" from HTML
    """UPDATE paragraph__field_code_block SET field_code_block_html = REPLACE(field_code_block_html, ' data-cbf-overlay-attached="1"', '') WHERE field_code_block_html LIKE '%data-cbf-overlay-attached%'""",
    # Remove data-cbf-link-handle-attached="1"
    """UPDATE paragraph__field_code_block SET field_code_block_html = REPLACE(field_code_block_html, ' data-cbf-link-handle-attached="1"', '') WHERE field_code_block_html LIKE '%data-cbf-link-handle-attached%'""",
    # Remove data-cbf-bg-image-attached="1"
    """UPDATE paragraph__field_code_block SET field_code_block_html = REPLACE(field_code_block_html, ' data-cbf-bg-image-attached="1"', '') WHERE field_code_block_html LIKE '%data-cbf-bg-image-attached%'""",
    # Also clean revision table
    """UPDATE paragraph_revision__field_code_block SET field_code_block_html = REPLACE(field_code_block_html, ' data-cbf-overlay-attached="1"', '') WHERE field_code_block_html LIKE '%data-cbf-overlay-attached%'""",
    """UPDATE paragraph_revision__field_code_block SET field_code_block_html = REPLACE(field_code_block_html, ' data-cbf-link-handle-attached="1"', '') WHERE field_code_block_html LIKE '%data-cbf-link-handle-attached%'""",
    """UPDATE paragraph_revision__field_code_block SET field_code_block_html = REPLACE(field_code_block_html, ' data-cbf-bg-image-attached="1"', '') WHERE field_code_block_html LIKE '%data-cbf-bg-image-attached%'""",
]

for sql in sql_commands:
    # Escape for shell
    escaped = sql.replace("'", "'\\''")
    cmd = f"cd {DRUPAL} && php vendor/bin/drush.php sql:query '{escaped}' 2>/dev/null"
    print(f"$ {sql[:120]}...")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(f"  Result: {out}")
    if err and len(err) < 300:
        print(f"  STDERR: {err}")
    print()

# Verify cleanup
print("=== Verification ===\n")
verify_commands = [
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT COUNT(*) FROM paragraph__field_code_block WHERE field_code_block_html LIKE \"%data-cbf-overlay-attached%\"' 2>/dev/null",
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT COUNT(*) FROM paragraph__field_code_block WHERE field_code_block_html LIKE \"%data-cbf-link-handle-attached%\"' 2>/dev/null",
    f"cd {DRUPAL} && php vendor/bin/drush.php sql:query 'SELECT COUNT(*) FROM paragraph__field_code_block WHERE field_code_block_html LIKE \"%data-cbf-bg-image-attached%\"' 2>/dev/null",
]

for cmd in verify_commands:
    print(f"$ {cmd[:150]}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    if out:
        print(f"  Count with stale flags: {out}")
    print()

# Clear Drupal cache
print("=== Clearing Drupal cache ===\n")
cmd = f"cd {DRUPAL} && php vendor/bin/drush.php cr 2>/dev/null"
stdin, stdout, stderr = client.exec_command(cmd, timeout=60)
out = stdout.read().decode('utf-8', errors='replace').strip()
print(out[:500] if out else "(no output)")

client.close()
