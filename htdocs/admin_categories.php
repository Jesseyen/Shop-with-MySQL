<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/admin_taxonomy.php';

taxonomy_page([
    'table'   => 'categories',
    'label'   => '分類',
    'entity'  => 'category',
    'ref_sql' => 'SELECT COUNT(*) FROM products WHERE category_id = ?',
    'script'  => 'admin_categories.php',
]);
