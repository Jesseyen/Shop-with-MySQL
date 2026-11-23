#!/bin/bash
# 重建測試資料庫、清空上傳檔，依序執行全部測試
set -e
cd "$(dirname "$0")/../sql"
sudo mariadb -e "DROP DATABASE IF EXISTS shop; CREATE DATABASE shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON shop.* TO 'shop'@'localhost';"
for f in 00_schema_full.sql 01_default_data.sql 10_sample_data.sql; do mariadb -ushop -pshoppass shop < $f; done
rm -f ../htdocs/uploads/products/*.webp ../htdocs/uploads/products/*.jpg ../htdocs/uploads/products/*.png ../htdocs/private_data/backups/* ../htdocs/private_data/error_fallback.log
rm -f ../htdocs/private_data/sessions/sess_*
cd ../tests
python3 test_stage1_4.py | tee /tmp/t14.txt | grep -v '^PASS'
python3 test_stage5_6.py | tee /tmp/t56.txt | grep -v '^PASS'
python3 test_stage3_archive.py | tee /tmp/t3.txt | grep -v '^PASS'
python3 test_switches.py | tee /tmp/tsw.txt | grep -v '^PASS'
