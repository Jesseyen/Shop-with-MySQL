<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/lib/admin_taxonomy.php';

taxonomy_page([
    'table'   => 'tags',
    'label'   => '標籤',
    'entity'  => 'tag',
    'ref_sql' => 'SELECT COUNT(*) FROM product_tags WHERE tag_id = ?',
    'script'  => 'admin_tags.php',
]);
