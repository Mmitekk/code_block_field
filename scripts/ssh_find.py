#!/usr/bin/env python3
"""SSH into the server and find the Drupal installation."""
import paramiko

HOST = '83.220.168.96'
USER = 'vysotniki'
PASS = '6oLA6Ve8WC'

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(HOST, username=USER, password=PASS, timeout=15)
print("=== Connected ===\n")

commands = [
    # Find the Drupal root
    "find / -maxdepth 5 -name 'code_block_field.info.yml' 2>/dev/null",
    # Also check common locations
    "ls -la /var/www/ 2>/dev/null",
    "ls -la /var/www/vysotniki-servis.ru/ 2>/dev/null",
    "ls -la /var/www/html/ 2>/dev/null",
    # Check home directory
    "ls -la ~/ 2>/dev/null | head -15",
    # Check web server config for the document root
    "grep -r 'DocumentRoot' /etc/httpd/ 2>/dev/null | head -5",
    "grep -r 'DocumentRoot' /etc/nginx/ 2>/dev/null | head -5",
    "grep -r 'vysotniki' /etc/httpd/ 2>/dev/null | head -5",
]

for cmd in commands:
    print(f"$ {cmd}")
    stdin, stdout, stderr = client.exec_command(cmd, timeout=30)
    out = stdout.read().decode('utf-8', errors='replace').strip()
    err = stderr.read().decode('utf-8', errors='replace').strip()
    if out:
        print(out)
    if err and len(err) < 200:
        print(f"  STDERR: {err}")
    print()

client.close()
