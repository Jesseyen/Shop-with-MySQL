<?php
/**
 * 分類與標籤共用管理頁（新增、修改、刪除；名稱唯一；已被引用時阻擋刪除）
 * 由 admin_categories.php、admin_tags.php 呼叫 taxonomy_page()。
 */
if (!defined('APP_BOOT')) { http_response_code(403); exit; }

function taxonomy_page(array $cfg): void
{
    global $pdo;
    $table = $cfg['table'];          // categories / tags
    $label = $cfg['label'];          // 分類 / 標籤
    $entity = $cfg['entity'];        // category / tag
    $refSql = $cfg['ref_sql'];       // 計算被引用次數的 SQL（參數：id）
    $script = $cfg['script'];
    require_perm('product', is_post() ? 'edit' : 'view');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require_csrf();
        $action = post_str('action');
        $id = to_pos_int($_POST['id'] ?? '');
        $name = post_str('name');
        try {
            if ($action !== 'delete') {
                $len = mb_strlen($name);
                if ($len < 1 || $len > 50) { throw new UserError("{$label}名稱須為 1～50 個字。"); }
            }
            admin_transaction(function (PDO $pdo, array $a) use ($action, $id, $name, $table, $entity, $refSql, $label) {
                if ($action === 'create') {
                    $pdo->prepare("INSERT INTO {$table} (name) VALUES (?)")->execute([$name]);
                    $newId = (int)$pdo->lastInsertId();
                    audit_log($pdo, $a['id'], $entity, $newId, 'create', null, ['id' => $newId, 'name' => $name]);
                    return;
                }
                if ($id === null) { throw new UserError('編號不正確。'); }
                $st = $pdo->prepare("SELECT id, name, row_version FROM {$table} WHERE id = ? FOR UPDATE");
                $st->execute([$id]);
                $row = $st->fetch();
                if (!$row) { throw new UserError("找不到此{$label}。"); }
                assert_row_version($row, $_POST['row_version'] ?? '');
                if ($action === 'update') {
                    $pdo->prepare("UPDATE {$table} SET name = ?, row_version = row_version + 1 WHERE id = ?")->execute([$name, $id]);
                    audit_log($pdo, $a['id'], $entity, $id, 'update', $row, ['id' => $id, 'name' => $name, 'row_version' => (int)$row['row_version'] + 1]);
                } elseif ($action === 'delete') {
                    $c = $pdo->prepare($refSql);
                    $c->execute([$id]);
                    $refs = (int)$c->fetchColumn();
                    if ($refs > 0) { throw new UserError("此{$label}已被 {$refs} 項商品使用，無法刪除。"); }
                    $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
                    audit_log($pdo, $a['id'], $entity, $id, 'delete', $row, null);
                } else {
                    throw new UserError('不支援的操作。');
                }
            }, 'product');
            flash('success', ['create' => "{$label}已新增。", 'update' => "{$label}已更新。", 'delete' => "{$label}已刪除。"][$action] ?? '完成。');
        } catch (UserError $e) {
            flash('error', $e->getMessage());
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash('error', strpos($e->getMessage(), 'foreign key') !== false || strpos($e->getMessage(), 'FOREIGN KEY') !== false
                    ? "此{$label}已被引用，無法刪除。" : "{$label}名稱「{$name}」已存在。");
            } else {
                throw $e;
            }
        }
        redirect($script);
    }

    $canEdit = admin_can('product', 'edit');
    $rows = $pdo->query("SELECT t.id, t.name, t.row_version, (" . str_replace('?', 't.id', $refSql) . ") AS refs
                           FROM {$table} t ORDER BY t.name")->fetchAll();
    page_header("{$label}管理", true);
    $seo = $table === 'categories' && $GLOBALS['enable_launch'];
    echo '<h1>' . h($label) . '管理</h1>';
    if ($canEdit) {
        echo '<section class="card"><h2>新增' . h($label) . '</h2><form method="post" action="' . h($script) . '" class="row-form">'
            . csrf_field() . '<input type="hidden" name="action" value="create">'
            . '<input type="text" name="name" maxlength="50" required placeholder="' . h($label) . '名稱" aria-label="' . h($label) . '名稱">'
            . '<button class="btn btn-primary" type="submit">新增</button></form></section>';
    }
    if (!$rows) {
        echo '<p class="empty">尚未建立' . h($label) . '。</p>';
        page_footer();
        return;
    }
    echo '<div class="table-wrap"><table><thead><tr><th>編號</th><th>名稱</th><th class="num">使用商品數</th><th>操作</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td>' . (int)$r['id'] . '</td><td>';
        if ($canEdit) {
            echo '<form method="post" action="' . h($script) . '" class="row-form">' . csrf_field()
                . '<input type="hidden" name="action" value="update"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                . '<input type="hidden" name="row_version" value="' . (int)$r['row_version'] . '">'
                . '<input type="text" name="name" value="' . h($r['name']) . '" maxlength="50" required aria-label="名稱">'
                . '<button class="btn btn-small" type="submit">儲存</button></form>';
        } else {
            echo h($r['name']);
        }
        echo '</td><td class="num">' . (int)$r['refs'] . '</td><td>';
        if ($seo) { echo '<a class="btn btn-small" href="admin_category_edit.php?id=' . (int)$r['id'] . '">說明與 SEO</a> '; }
        if ($canEdit) {
            if ((int)$r['refs'] > 0) {
                echo '<span class="muted small">已被引用，不可刪除</span>';
            } else {
                echo '<form method="post" action="' . h($script) . '" class="inline" onsubmit="return confirm(\'確定刪除？\')">' . csrf_field()
                    . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                    . '<input type="hidden" name="row_version" value="' . (int)$r['row_version'] . '">'
                    . '<button class="btn btn-small btn-danger" type="submit">刪除</button></form>';
            }
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    page_footer();
}
